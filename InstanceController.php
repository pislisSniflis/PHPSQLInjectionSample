<?php

namespace App\Http\Controllers\Web\Backups;

use App\Api;
use Faker\Factory;
use App\ApiService;
use App\Helpers\Flash;
use App\Helpers\Filter;
use App\Helpers\Paginator;
use Illuminate\Http\Request;
use GuzzleHttp\RequestOptions;
use App\Traits\CreateBackupInstance;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use RunCloudIO\InternalSDK\Helpers\Helper;

class InstanceController extends Controller
{
    use CreateBackupInstance;
    
    public function index()
    {
        $user               = auth()->user();
        $backupModule       = app('BackupModule')->setUser($user);
        $subscriptionModule = app('SubscriptionModule')->setUser($user);
        $backup             = $subscriptionModule->getSubscriptionItemFor('backup');
        $storage            = $subscriptionModule->getSubscriptionItemFor('backup-storage-v2');
        $server             = $subscriptionModule->getPlanFor('server');
        $services           = app('ExternalAPIService')->getAllByType('Backup Storage');
        $service            = function (String $name) {
            return app('RunCloud.InternalSDK')->service($name);
        };

        $result = app('RunCloud.InternalSDK')
            ->multiService([
                'summary'    => $service('backup')
                    ->get('/internal/backup/summary')
                    ->payload([
                        RequestOptions::JSON => [
                            'user_id'           => auth()->user()->id,
                            'plan'              => $subscriptionModule->getPlanFor('server')->rolesToAssign,
                            'subscribedStorage' => !$storage ? null : [
                                'size'            => sizeToBytes(sprintf("%s%s", $storage->plan->meta['size'], $storage->plan->meta['sizeUnit'])),
                                // 'monthlyDownload' => (int) $storage->plan->meta['monthlyDownload'],
                                'sites'           => $backup ? $backup->quantity : 0,
                            ],
                        ],
                    ]),
                'sites'      => $service('backup')
                    ->get('/internal/sites')
                    ->payload([
                        RequestOptions::JSON => Filter::request(request(), [
                            'user_id' => auth()->user()->id,
                            'perPage' => 20,
                            'search'  => [
                                'storage'   => request('storage'),
                                'name'      => request('search-pd'),
                                'server_id' => request('server_id'),
                            ], // todo: search by storage & external service provider
                            'timeout' => 1500,
                        ]),
                    ]),
                'ownServers' => $service('server')
                    ->get('/internal/resources/find/Server/get')
                    ->payload([
                        RequestOptions::JSON => [
                            'where'  => [
                                'user_id' => auth()->user()->id,
                            ],
                            'select' => ['id', 'name'],
                        ],
                    ]),
            ])
            ->execute();

        $backupServices = app('ExternalAPIService')
                    ->setUser(auth()->user())
                    ->getActiveBackupStorage();
        
        $enableInfo = true;

        if (request()->all() != []) {
            if ((request('storage') != null && request('storage') != '0') || (request('server_id') != null && request('server_id') != '0') || (request('page') != null && request('page') != '1') || request('search-pd') != null) {
                $enableInfo = false;
            }
        }

        $serverRelated = [
            '0' => 'All Servers',
        ];

        foreach (collect($result['ownServers']) as $server) {
            $serverRelated[$server->id] = $server->name;
        }

        $allowedStorage = app('ExternalAPIService')
                    ->setUser(auth()->user())
                    ->getGroupedAllowedExternalStorage();

        return view('backups.instances.index', [
            'summary'        => $result['summary'],
            'sites'          => Paginator::generate($result['sites']),
            'backupServices' => collect($backupServices)->toJson(),
            'services'       => $services,
            'trialEnded'     => null, // no more backup trial
            'role'           => auth()->user()->roleName,
            'enableInfo'     => $enableInfo,
            'serverRelated'  => collect($serverRelated)->toJson(),
            'allowedStorage' => $allowedStorage
        ]);
    }

    public function getAllowedExternalStorage()
    {
        $storages = app('ExternalAPIService')
                    ->setUser(auth()->user())
                    ->getGroupedAllowedExternalStorage();

        return response()->json([
            'data' => $storages
        ]);
    }
    
    public function create(Request $request)
    {
        $selectedWebAppId = $request->webAppId;
        $subscriptionModule = app('SubscriptionModule')->setUser(auth()->user());
        $plan               = strtolower($subscriptionModule->getPlanFor('server')->public_plan_name);

        $backup  = $subscriptionModule->getSubscriptionItemFor('backup');
        $service = function (String $name) {
            return app('RunCloud.InternalSDK')->service($name);
        };

        $storages = app('ExternalAPIService')
                    ->setUser(auth()->user())
                    ->getGroupedAllowedExternalStorage();

        $services = app('ExternalAPIService')->getAllByType('Backup Storage');

        $resourcePayload = [
            'user_id' => auth()->user()->id,
            'group'   => true,
        ];

        $result = app('RunCloud.InternalSDK')
            ->multiService([
                'webApps'           => $service('server')
                    ->get('/internal/selection/backup/webapps/withbinded')
                    ->payload([RequestOptions::JSON => $resourcePayload]),
                'databases'         => $service('server')
                    ->get('/internal/selection/backup/databases')
                    ->payload([RequestOptions::JSON => $resourcePayload]),
                'stats'             => $service('backup')
                    ->get('/internal/special/stats')
                    ->payload([RequestOptions::JSON => [
                        'userId' => auth()->user()->id,
                        'plan'   => $plan,
                    ]]),
                'backupTimeList'    => $service('backup')->get('/internal/selection/backuptime'),
                'retentionTimeList' => $service('backup')->get('/internal/selection/retentiontime'),
                'availableFormats'  => $service('backup')->get('/internal/selection/backupfileformat'),
                'bucketName'        => $service('backup')
                    ->get('/internal/special/bucketname')
                    ->payload([RequestOptions::JSON => ['user_id' => auth()->user()->id]]),
            ])
            ->execute();

        $result['stats']->storage = $this->converStatsToHumanReadable($result['stats']->storage);

        return view('backups.instances.create', [
            'selectedWebAppId'  => (int)$selectedWebAppId,
            'storages'          => $storages,
            'services'          => $services,
            'servers'           => $result['webApps']->servers,
            'webApps'           => $result['webApps']->selection,
            'databases'         => $result['databases'],
            'binded'            => $result['webApps']->binded,
            'stats'             => $result['stats'],
            'backupTimeList'    => $result['backupTimeList'],
            'retentionTimeList' => $result['retentionTimeList'],
            'availableFormats'  => $result['availableFormats'],
            'bucketName'        => $result['bucketName']->name, //!$backup && !$trial ? 'basic' : 'pro',
            'trialEnded'        => null, // no more backup trial
            'role'              => auth()->user()->roleName,
        ]);
    }

    public function overview($backupId)
    {
        // overview data
        $site = app('RunCloud.InternalSDK')
            ->service('backup')
            ->get(sprintf('/internal/sites/%s/overview', $backupId))
            ->payload([
                RequestOptions::JSON => Filter::request(request(), [
                    'perPage' => 20,
                ]),
            ])
            ->execute();

        $snapshots = Paginator::generate($site->snapshots, null, ['pageName' => 'page']);
        $role      = auth()->user()->role_name;
        $allowedStorage = app('ExternalAPIService')
                    ->setUser(auth()->user())
                    ->getGroupedAllowedExternalStorage();

        return view('backups.instances.overview', [
            'siteOverview'  => $site->data->site,
            'site'          => $site,
            'snapshots'     => $snapshots,
            'features'      => $this->backupUpgradeFeatures(),
            'role'          => $role,
            'allowedStorage'=> $allowedStorage
        ]);
    }

    public function edit(String $backupId)
    {
        $subscriptionModule = app('SubscriptionModule')->setUser(auth()->user());
        $backup             = $subscriptionModule->getSubscriptionItemFor('backup');
        $service            = function (String $name) {
            return app('RunCloud.InternalSDK')->service($name);
        };

        $result = app('RunCloud.InternalSDK')
            ->multiService([
                'server'            => $service('backup')->get('/internal/special/server-info')->payload([
                    RequestOptions::JSON => ['backupId' => $backupId],
                ]),
                'backupTimeList'    => $service('backup')->get('/internal/selection/backuptime'),
                'retentionTimeList' => $service('backup')->get('/internal/selection/retentiontime'),
                'availableFormats'  => $service('backup')->get('/internal/selection/backupfileformat'),
                'stats'             => $service('backup')
                    ->get('/internal/special/stats')
                    ->payload([RequestOptions::JSON => ['userId' => auth()->user()->id]]),
            ])
            ->execute();

        $result['stats']->storage = $this->converStatsToHumanReadable($result['stats']->storage);

        return view('backups.instances.edit', [
            'server'            => $result['server'],
            'features'          => $this->backupUpgradeFeatures(),
            'stats'             => $result['stats'],
            'backupTimeList'    => $result['backupTimeList'],
            'retentionTimeList' => $result['retentionTimeList'],
            'availableFormats'  => $result['availableFormats'],
            'trialEnded'        => null, // no more backup trial
            'role'              => auth()->user()->roleName,
        ]);
    }

    public function update($siteId)
    {
        $backupable = [];
        if (request()->instanceType == 'App\WebApplication' || request()->instanceType == 'both') {
            $backupable[] = $this->getBackupableDetails('WebApplication', [
                'id'      => request()->backupableWebapp,
                'exclude' => [
                    'files'                         => request()->excludeWebapp,
                    'exclude_development_files'     => request()->exclude_development_files,
                    'exclude_wordpress_cache_files' => request()->exclude_wordpress_cache_files,
                ],
            ]);
        }

        if (request()->instanceType == 'App\Database' || request()->instanceType == 'both') {
            $backupable[] = $this->getBackupableDetails('Database', [
                'id'      => request()->backupableDb,
                'exclude' => request()->excludeTable,
            ]);
        }

        app('RunCloud.InternalSDK')
            ->service('backup')
            ->patch(sprintf('/internal/sites/%s', $siteId))
            ->payload([
                RequestOptions::JSON => Filter::request(request(), [
                    'user_id'    => auth()->user()->id,
                    'backupable' => $backupable,
                ])
            ])
            ->execute();

        Flash::success('Successfully updated backup settings');

        return response()->json([
            'redirect' => route('backup:instances:index'),
        ]);
    }

    public function files($backupId)
    {
        $files = app('RunCloud.InternalSDK')
            ->service('backup')
            ->get(sprintf('/internal/sites/%s/files', $backupId))
            ->payload([
                RequestOptions::JSON => Filter::request(request(), [
                    'perPage' => 10,
                ]),
            ])
            ->execute();

        $trial  = app('BackupModule')->setUser(auth()->user())->trial();
        $backup = app('SubscriptionModule')->setUser(auth()->user())->getSubscriptionItemFor('backup');

        return view('backups.instances.files', [
            'files'      => Paginator::generate($files),
            'backupPlan' => !$backup && !$trial ? 'basic' : 'pro',
            'features'   => $this->backupUpgradeFeatures(),
        ]);
    }

    public function legacyFiles($backupId)
    {
        $files = app('RunCloud.InternalSDK')
            ->service('backup')
            ->get(sprintf('/internal/sites/%s/legacy/files', $backupId))
            ->payload([
                RequestOptions::JSON => Filter::request(request(), [
                    'perPage' => 10,
                ]),
            ])
            ->execute();

        $trial  = app('BackupModule')->setUser(auth()->user())->trial();
        $backup = app('SubscriptionModule')->setUser(auth()->user())->getSubscriptionItemFor('backup');

        return view('backups.instances.files', [
            'files'      => Paginator::generate($files),
            'backupPlan' => !$backup && !$trial ? 'basic' : 'pro',
            'isLegacy'   => true,
        ]);
    }

    public function createBackupContainer($backupId)
    {
        try {
            app('RunCloud.InternalSDK')
                ->service('backup')
                ->post(sprintf('/internal/sites/%s/backupcontainer', $backupId))
                ->execute();

            app('RunCloud.Analytic')
                ->trackBy(auth()->user()->id)
                ->doing('force backup')
                ->payload(['successStatus' => true])
                ->import();

            return response()->json([
                'message' => 'Your backup will start shortly',
            ]);
        } catch (\Exception $e) {
            app('RateLimitModule')
                ->init(sprintf('forcebackup-%s', $backupId))
                ->setUser(auth()->user())
                ->reset();

            app('RunCloud.Analytic')
                ->trackBy(auth()->user()->id)
                ->doing('force backup')
                ->payload(['successStatus' => false])
                ->import();

            throw $e;
        }
    }

    public function delete($backupId)
    {
        $backup = app('RunCloud.InternalSDK')
            ->service('backup')
            ->delete(sprintf('/internal/sites/%s', $backupId))
            ->execute();

        // if ($backup->backupable_type == 'App\WebApplication') {
        //     Flash::success(sprintf('Successfully deleted backup instance for Web Application %s', $backup->name));
        // } else if ($backup->backupable_type == 'App\Database') {
        //     Flash::success(sprintf('Successfully deleted backup instance for Database %s', $backup->name));
        // }

        app('RunCloud.Analytic')
            ->trackBy(auth()->user()->id)
            ->doing('delete backup')
            ->payload([])
            ->import();

        Flash::success('Successfully deleted backup site');

        return response()->json([
            'redirect' => url()->previous(),
        ]);
    }

    public function store(Request $request)
    {
        // function moved to CreateBackupInstance Trait
        $backup = $this->storeBackupInstance($request);

        Flash::success('Successfully created backup instance.');

        return response()->json([
            'redirect' => route('backup:instances:summary', $backup->site_id),
        ]);
    }

    // makeAnalyticPayload() moved to CreateBackupInstance Trait

    public function restore($siteId)
    {
        $user    = auth()->user();
        $service = function (String $name) {
            return app('RunCloud.InternalSDK')
                ->service($name);
        };

        $result = app('RunCloud.InternalSDK')
            ->multiService([
                'snapshots'  => $service('backup')->get(sprintf('/internal/selection/%s/snapshots', $siteId))
                    ->payload([
                        RequestOptions::JSON => [
                            'withType' => true,
                            'group'    => true,
                        ],
                    ]),
                'site'       => $service('backup')->get('/internal/resources/find/Site/first')
                    ->payload([
                        RequestOptions::JSON => [
                            'where'    => [
                                'id'   => $siteId,
                            ],
                            'includes' => 'backups',
                        ],
                    ]),
                'ownServers' => $service('server')->get('/internal/resources/find/Server/get')
                    ->payload([
                        RequestOptions::JSON => [
                            'where' => [
                                'user_id' => $user->id,
                                'online'  => true,
                            ],
                        ],
                    ]),
            ])
            ->execute();

        $current = [];
        $backups = collect($result['site']->backups);
        if (count($backups) > 0) {
            $current['server'] = $result['site']->server_id;
            foreach ($result['site']->backups as $backup) {

                if ($backup->backupable_type == 'App\WebApplication') {
                    $current['webapp'] = $backup->backupable_id;
                }

                if ($backup->backupable_type == 'App\Database') {
                    $current['database'] = $backup->backupable_id;
                }
            }
        }

        $servers = collect($result['ownServers'])->mapWithKeys(function ($server) use ($current) {
            if (isset($current['server']) && $server->id == $current['server']) {
                return [$server->id => $server->name . ' ( Current Server ) '];
            }

            return [$server->id => $server->name];
        });

        // if local backup, keep only current server
        if ($result['site']->storage === 'local') {
            foreach ($servers as $server_id => $server_name) {
                if ($server_id !== $current['server']) {
                    unset($servers[$server_id]);
                }
            }
        }

        $credentials = app('DomainManagerService')->getAllApiKeys();
        $cfApiKeys   = [];

        $credentials->each(function ($credential) use (&$cfApiKeys) {
            if ($credential->apiService->name == 'Cloudflare') {
                $cloudflareAccounts = app('DomainManagerService')
                    ->service('cloudflare')
                    ->load([
                        'email'  => $credential->username,
                        'apiKey' => $credential->secret,
                    ])->listAllAccount();

                if ($cloudflareAccounts) {
                    $credential->account = $cloudflareAccounts;
                    array_push($cfApiKeys, $credential);
                }
            }
        });

        $canRestore     = $result['site']->can_restore;
        $disableCurrent = false;
        $disableOther   = false;
        $disableNew     = false;
        $tooltipCurrent = '';
        $tooltipOther   = '';
        $tooltipNew     = '';
        $targetDefault  = 'current';

        if ($result['site']->archived) {
            $targetDefault  = 'other';
            $disableCurrent = true;
            $tooltipCurrent = 'Can not restore to the same target because backup has been archived';
        }

        // example: disable restore to other/new for full backup
        // if ($result['site']->backup_type == 'full') {
        //     $disableOther   = true;
        //     $disableNew     = true;
        //     $tooltipOther   = 'Coming soon, currently restore to the other target is not available for full backup';
        //     $tooltipNew     = 'Coming soon, currently restore to the new target is not available for full backup';
        // }

        if ($disableCurrent && $disableOther && $disableNew) {
            $canRestore = false;
        }

        $backupStorageIcon = $result['site']->storageInfo->icon ?? '';
        $backupStorageName = $result['site']->storageInfo->label ?? '';
        $backupStorageSlug = $result['site']->storageInfo->slug ?? '';

        if ($backupStorageSlug === 'amazon') {
            $backupStorageIcon = 'br-s3';
        } else if ($backupStorageSlug === 'dospace') {
            $backupStorageIcon = 'br-digitalocean';
        }

        // check if site use atomic deployment
        // if site use atomic deployment, then it can't restore
        // we also put isAtomic is true so later in vue view can show correct error messages
        $site = $result['site'];
        if (!empty($site->backups) && !empty(collect($site->backups)->where('backupable_type', 'App\WebApplication')->first())) {
            $webApp = collect($site->backups)->where('backupable_type', 'App\WebApplication')->first();

            $version_control = app('RunCloud.InternalSDK')
                ->service('server')
                ->get('/internal/resources/find/VersionControl/first')
                ->payload([
                    \GuzzleHttp\RequestOptions::JSON => [
                        'where'    => [
                            'web_application_id' => $webApp->backupable_id,
                        ],
                        'includes' => 'atomicProject',
                    ],
                ])
                ->execute();


            $isAtomic = !is_null($version_control) && $version_control->atomic ? 1 : 0;

            if ($isAtomic) {
                $canRestore = false;
            }
        }

        return view('backups.files.restore', [
            'backupOverviewUrl' => route('backup:instances:summary', $siteId),
            'user_id'           => !empty($result['ownServers']) ? collect($result['ownServers'])->first()->user_id : Auth::user()->id,
            'current'           => $current,
            'servers'           => $result['ownServers'],
            'sitePlan'          => $result['site']->storage,
            'type'              => $result['site']->contain,
            'suggestedName'     => sprintf('app-%s', Factory::create('en_US')->unique()->domainWord),
            'accounts'          => app('DomainManagerService')->getAllAccount(),
            'selections'        => [
                'snapshots'     => $result['snapshots'],
                'servers'       => $servers,
            ],
            'cfApiKeys'         => $cfApiKeys,
            'backupName'        => $result['site']->name,
            'backupType'        => $result['site']->backup_type,
            'backupStatusCode'  => $result['site']->status_code,
            'backupStorage'     => $result['site']->storage,
            'backupStorageIcon' => $backupStorageIcon,
            'backupStorageName' => $backupStorageName,
            'canRestore'        => $canRestore,
            'disableCurrent'    => $disableCurrent,
            'disableOther'      => $disableOther,
            'disableNew'        => $disableNew,
            'tooltipCurrent'    => $tooltipCurrent,
            'tooltipOther'      => $tooltipOther,
            'tooltipNew'        => $tooltipNew,
            'targetDefault'     => $targetDefault,
            'isAtomic'          => isset($isAtomic) ? $isAtomic : 0,
            'noNeedVerification' => in_array($result['site']->storage, ['local', 'sftp', 'runcloud'])
        ]);
    }

    public function getRestoreSetup(Request $request)
    {
        $result = app('RunCloud.InternalSDK')
            ->multiService([
                'webapps'     => app('RunCloud.InternalSDK')
                    ->service('server')
                    ->get('/internal/resources/find/WebApplication/get')
                    ->payload([
                        RequestOptions::JSON => [
                            'where'    => [
                                'server_id' => $request->get('serverId'),
                            ],
                            'includes' => ['versionControl'],
                        ],
                    ]),
                'database'    => app('RunCloud.InternalSDK')
                    ->service('server')
                    ->get('/internal/resources/find/Database/get')
                    ->payload([
                        RequestOptions::JSON => [
                            'where' => [
                                'server_id' => $request->get('serverId'),
                            ],
                        ],
                    ]),
                'serverSetup' => app('RunCloud.InternalSDK')
                    ->service('server')
                    ->get('/internal/resources/find/ServerSetup/first')
                    ->payload([
                        RequestOptions::JSON => [
                            'where' => [
                                'server_id' => $request->get('serverId'),
                            ],
                        ],
                    ]),
            ])
            ->multiOnly([
                'serverSetup' => ['test_domain_zone', 'test_domain'],
            ])
            ->execute();

        $webapps = collect($result['webapps'])->filter(function ($webapp) use ($request) {
            // Accept all accept atomic which not itself
            return !($webapp->id != $request->get('currentWebApp') && $webapp->version_control && $webapp->version_control->atomic);
        });

        return response()->json([
            $webapps->pluck('name', 'id'),
            collect($result['database'])->pluck('name', 'id'),
            is_object($result['serverSetup']) ? collect($result['serverSetup'])->toArray() : null,
        ]);
    }

    public function getActiveSitesCount($serverId)
    {
        $count = app('RunCloud.InternalSDK')
            ->service('backup')
            ->get('/internal/resources/find/Site/count')
            ->payload([
                RequestOptions::JSON => [
                    'where'    => [
                        'user_id'   => auth()->user()->id,
                        'server_id' => $serverId
                    ],
                ],
            ])
            ->execute();

        return response()->json([
            'count' => $count,
        ]);
    }

    // getBackupableDetails() moved to CreateBackupInstance Trait

    private function converStatsToHumanReadable($storageStats)
    {
        return collect($storageStats)->transform(function ($storage) {
            return collect($storage)->transform(function ($data) {
                if ($data <= 0 || !is_numeric($data)) {
                    return Helper::getHumanReadableSize(0, 2, 1024);
                }
                return Helper::getHumanReadableSize($data, 2, 1024);
            });
        });
    }

    private function backupUpgradeFeatures()
    {
        return [
            'Free up to 50GB pro storage',
            'Pro storage plan up to 3TB',
            'Backup frequency from every 30 minutes to 1 week',
            'Backup retention up to 1 month',
            'Folder and file exclusion',
            'Database table exclusion',
            'On-demand backup',
            'Download backup',
            'Restore to existing or new site',
            'Restore to RunCloud test domain',
        ];
    }

    /**
     * Check file integrity using checksum verification.
     * currently, only external S3 and local backup that use checksum verification.
     * for local backup, checksum verification will done at runcloud-server, it will run shell script to compare checksum
     * for external S3, checksum verification will done at runcloud-backup, just get etag attribute from S3 for comparing it, so need to download the backupfile
     */
    public function verifyChecksum(Request $request)
    {
        $service               = $request->get('storage') == "local" ? "server" : "backup";
        $snapshot              = $request->get('snapshot')[0];
        $snapshot['server_id'] = $request->get('serverId');

        $checksum = app('RunCloud.InternalSDK')
            ->service($service)
            ->post("/internal/special/backup/verify-checksum")
            ->payload([
                RequestOptions::JSON => [
                    'backupData' => $snapshot,
                    'storage'    => $request->get('storage'),
                ],
            ])
            ->execute();

        if (!$checksum->success) {
            abort(403, 'The backup file not passed integrity file, backup maybe corrupt or broken.');
        }

        return json_encode($checksum);
    }
}
