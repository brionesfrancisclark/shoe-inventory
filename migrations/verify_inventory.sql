-- =====================================================================================
-- Footwear Inventory - READ-ONLY check. Changes nothing; safe to run any time.
--
-- Run it BEFORE and AFTER migration 001 and compare: every number and fingerprint in
-- parts 1 and 2 must be identical (the migration must not change any shoe, stock
-- quantity or account).
--   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/verify_inventory.sql"
-- =====================================================================================

SET SESSION group_concat_max_len = 67108864;

-- 1. Counts and totals
SELECT DATABASE()                                         AS database_name,
       (SELECT COUNT(*) FROM shoes)                       AS shoe_models,
       (SELECT COUNT(*) FROM shoe_sizes)                  AS size_rows,
       (SELECT COALESCE(SUM(quantity), 0) FROM shoe_sizes) AS pairs_in_stock,
       (SELECT COUNT(*) FROM users)                       AS accounts,
       (SELECT COUNT(*) FROM users WHERE role = 'admin')  AS admin_accounts,
       (SELECT COUNT(*) FROM users WHERE role = 'staff')  AS staff_accounts;

-- 2. Fingerprints: identical before and after = no shoe, stock row or account changed
SELECT
  (SELECT MD5(COALESCE(GROUP_CONCAT(CONCAT_WS('|', id, name, brand, category, price, COALESCE(image_url, ''))
                                    ORDER BY id SEPARATOR '\n'), '')) FROM shoes)      AS shoes_fingerprint,
  (SELECT MD5(COALESCE(GROUP_CONCAT(CONCAT_WS('|', id, shoe_id, size_number, quantity)
                                    ORDER BY id SEPARATOR '\n'), '')) FROM shoe_sizes) AS stock_fingerprint,
  (SELECT MD5(COALESCE(GROUP_CONCAT(CONCAT_WS('|', id, username, display_name, password_hash, role, is_active,
                                              failed_attempts, COALESCE(locked_until, ''), COALESCE(last_login, ''), created_at)
                                    ORDER BY id SEPARATOR '\n'), '')) FROM users)      AS accounts_fingerprint;

-- 3. Stock per shoe model
SELECT s.id, s.brand, s.name, COUNT(z.id) AS size_rows, COALESCE(SUM(z.quantity), 0) AS pairs
  FROM shoes s
  LEFT JOIN shoe_sizes z ON z.shoe_id = s.id
 GROUP BY s.id, s.brand, s.name
 ORDER BY s.id;

-- 4a. Shoes that count as the SAME model (same brand + name, ignoring capitals and extra
--     spaces). Must be empty before migrating - otherwise merge or rename them first.
SELECT GROUP_CONCAT(id ORDER BY id) AS shoe_ids, MIN(brand) AS brand, MIN(name) AS name, COUNT(*) AS copies
  FROM shoes
 GROUP BY LOWER(TRIM(REGEXP_REPLACE(brand, '[[:space:]]+', ' '))) COLLATE utf8mb4_bin,
          LOWER(TRIM(REGEXP_REPLACE(name,  '[[:space:]]+', ' '))) COLLATE utf8mb4_bin
HAVING COUNT(*) > 1;

-- 4b. Admin accounts. Exactly one row is expected (only one admin is allowed).
SELECT id, username, display_name, is_active FROM users WHERE role = 'admin';
