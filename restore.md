##Just copy this script##
mysql -u root -p
create database sample;
connect sample;
create table users(username VARCHAR(100),password VARCHAR(100));
insert into users values('sammy','password');
quit;
