-- Backup generated 2026-10-09 08:00:42
SELECT * FROM users WHERE id IN (19,21,23,25,27,29,31,33);
SELECT * FROM wallets WHERE user_id IN (19,21,23,25,27,29,31,33);
SELECT * FROM admin_logs WHERE user_id IN (19,21,23,25,27,29,31,33);
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (19,'Modal Auditor','modal-audit-6bd1c766@example.com','$2y$10$fNGCS8oMjU2.nPiKShqiM.S6sMxwzrku5F73JM5Tqpk7JNJRlv7lu',1,0,'active','2026-10-08 15:31:44');
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (21,'Modal Auditor','modal-audit-73701628@example.com','$2y$10$8WwY1t2YA5EuVTo9Llc61uNj50MdYME/dBGVS5r0heNFXPgE19u5C',1,0,'active','2026-10-08 15:33:19');
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (23,'Modal Auditor','modal-audit-78607fab@example.com','$2y$10$kXou.92asQipsgwzcN/pIuY7SLo/NR47GJSdFBHHN93DHhEjWk34u',1,0,'active','2026-10-08 15:36:46');
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (25,'Modal Auditor','modal-audit-94396ad0@example.com','$2y$10$snzLXOLI3pDcEC3lSZbtXeQvQHdkIbnC4fBr5JSeV1KxvSNB3quuC',1,0,'active','2026-10-08 15:37:18');
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (27,'Modal Auditor','modal-audit-067e6ad6@example.com','$2y$10$t5LR07KK.OsvcYb/u1uWCOOJOYBVxCUXMbxV/PkOAbPO9v9hyHUCy',1,0,'active','2026-10-08 15:40:06');
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (29,'Modal Auditor','modal-audit-a931f7ed@example.com','$2y$10$y0eJSluCmOkK0ZuDf.FucuO/M3lmQh.by.sYn5QhVM6u32ZnInR5q',1,0,'active','2026-10-08 15:41:08');
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (31,'Modal Auditor','modal-audit-1895cd4b@example.com','$2y$10$Lx24jwXr.T9hY4.Wkc4iU.fHWfdu1DptXoM6ORK2KunPvGNqtppLK',1,0,'active','2026-10-08 15:47:56');
INSERT INTO users (id,name,email,password,is_admin,is_super_admin,status,created_at) VALUES (33,'Modal Auditor','modal-audit-89a67ff2@example.com','$2y$10$jBsy0SdEqhtwc5KmpD3qtuWPfWomhEaniU7fCaQqQvVNxnGG7WazO',1,0,'active','2026-10-08 15:51:11');
DELETE FROM admin_logs WHERE id=20; -- user_id=19 admin_access
DELETE FROM admin_logs WHERE id=21; -- user_id=21 admin_access
DELETE FROM admin_logs WHERE id=22; -- user_id=23 admin_access
DELETE FROM admin_logs WHERE id=23; -- user_id=25 admin_access
DELETE FROM admin_logs WHERE id=24; -- user_id=27 admin_access
DELETE FROM admin_logs WHERE id=25; -- user_id=27 refresh_transaction_status
DELETE FROM admin_logs WHERE id=26; -- user_id=29 admin_access
DELETE FROM admin_logs WHERE id=27; -- user_id=31 admin_access
DELETE FROM admin_logs WHERE id=28; -- user_id=31 refresh_transaction_status
DELETE FROM admin_logs WHERE id=29; -- user_id=33 admin_access
DELETE FROM admin_logs WHERE id=30; -- user_id=33 refresh_transaction_status
DELETE FROM wallets WHERE id=19; -- user_id=19
DELETE FROM wallets WHERE id=21; -- user_id=21
DELETE FROM wallets WHERE id=23; -- user_id=23
DELETE FROM wallets WHERE id=25; -- user_id=25
DELETE FROM wallets WHERE id=27; -- user_id=27
DELETE FROM wallets WHERE id=29; -- user_id=29
DELETE FROM wallets WHERE id=31; -- user_id=31
DELETE FROM wallets WHERE id=33; -- user_id=33
