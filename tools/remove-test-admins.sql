-- =====================================================================
-- Removal of leftover admin test-fixture accounts from reup_old
-- =====================================================================
-- These accounts were created by a headless-Chrome script driving the
-- admin self-registration endpoint during a pre-isolation test run on
-- 2026-10-08 (see admin_logs rows 11-31, User-Agent HeadlessChrome/153).
--
-- Why they are a security problem, not just clutter:
--   * modal-audit-* accounts carry is_admin = 1 (admin console access);
--   * all eight share one predictable fixture password;
--   * the admin console can move money, approve transfers and change pricing.
--
-- The account name "Modal Auditor" and the audit-*/modal-audit-* emails
-- appear nowhere in the current source tree, confirming they are orphaned
-- database residue rather than application data.
--
-- Retained deliberately (real accounts/behaviour):
--   id 13  muyiwadavis65@gmail.com      real signup
--   id 14  superadmin@reup.com.ng       the AdminSeeder super admin
--   id 15  media.bellahoptions2.0@gmail.com  real account, funded, 19 txns
--   id 16-18, 20, 22, ... faker users and audit-* rows  -> NOT touched
--
-- Backup of every removed row is in
--   tools/removed-test-admins-backup.sql
-- =====================================================================

START TRANSACTION;

-- Dependent rows first (FKs are RESTRICT, not CASCADE).
DELETE FROM admin_logs WHERE user_id IN (19,21,23,25,27,29,31,33);
DELETE FROM wallets    WHERE user_id IN (19,21,23,25,27,29,31,33);
DELETE FROM users      WHERE id      IN (19,21,23,25,27,29,31,33);

COMMIT;
