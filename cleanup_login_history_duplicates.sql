-- OPTIONAL one-time cleanup: removes the duplicate rows already in
-- login_history (same user, same second). Run once in phpMyAdmin > SQL tab.
USE school_system;

DELETE a FROM login_history a
JOIN login_history b
  ON  a.role = b.role
  AND a.user_id = b.user_id
  AND a.logged_in_at = b.logged_in_at
  AND a.id > b.id;
