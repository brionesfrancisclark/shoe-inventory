# Footwear Inventory

A shoe inventory app that runs on a Windows computer with XAMPP.

- **Shoe models with colorways.** Each colorway has its own photo, sizes and stock.
- **One model per brand and name.** Typing "Nike / Air Force 1" twice adds colorways to the same model. Capitals and extra spaces are ignored.
- **Stock history:** who changed what, when, and by how much.
- **Staff accounts** with a permission checklist for each person.
- **Exactly one Admin account**, protected by the database itself.

---

## What you need

| | |
|---|---|
| Computer | Windows 10 or 11 |
| XAMPP | A recent version with **PHP 8.1 or newer** (tested with PHP 8.5 and MariaDB 10.11) |
| Database | The **MariaDB** that comes with XAMPP. The database scripts use MariaDB features, so plain MySQL is not supported. |
| Browser | Edge, Chrome or Firefox |

The PHP extensions `pdo_mysql`, `fileinfo` and `mbstring` must be on. They are on by default in XAMPP. To check, open `C:\xampp\php\php.ini` and make sure these lines do not start with `;`:

```
extension=fileinfo
extension=mbstring
extension=pdo_mysql
```

The commands below assume XAMPP is installed in `C:\xampp`. Run them in **Command Prompt** or **PowerShell**.

---

## A. New installation (empty inventory)

### 1. Install XAMPP and start it
1. Install XAMPP.
2. Open the **XAMPP Control Panel**.
3. Click **Start** next to **Apache** and **MySQL**. Both should turn green.

### 2. Copy the app
Copy the app folder to `C:\xampp\htdocs\shoe-inventory`.

To get it from GitHub, use either way:
- **Download:** on the repository page, click **Code → Download ZIP**. Unzip it, rename the folder to `shoe-inventory`, and move it into `C:\xampp\htdocs\`.
- **Git:** run this, using your account name:
  ```
  git clone https://github.com/<your-account>/shoe-inventory.git C:\xampp\htdocs\shoe-inventory
  ```

The repository has no shoe photos and no data; those stay on the shop computer (see part B).

Copy only the app itself. **Do not copy** any of these:
- a `staging` folder (that is a test copy);
- backup folders;
- the contents of `uploads` (unless you are moving data, see part B).

The folder should look like this:

```
C:\xampp\htdocs\shoe-inventory\
    add-shoe.php  admin.php  auth.php  colorway.php  db.php  image-lib.php  index.php
    inventory-history.php  inventory-lib.php  layout.php  login.php  logout.php
    permissions-lib.php  shoe.php  user-permissions.php  users.php
    schema.sql  README.md
    migrations\001_colorways_permissions.sql
    migrations\verify_inventory.sql
```

### 3. Create the database
Run these two commands, one after the other:

```
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE shoe_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"

C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/schema.sql"
```

- The second command must print nothing. If it prints an `ERROR`, see *Troubleshooting*.
- `schema.sql` only works on an **empty** database. It refuses to run if the app's tables already exist.
- If your MySQL `root` user has a password, add `-p` after `-uroot` and type the password when asked.

### 4. Check the database settings
Open `C:\xampp\htdocs\shoe-inventory\db.php` in a text editor and check these lines:

```php
const DB_HOST      = 'localhost';
const DB_NAME      = 'shoe_inventory';
const DB_USER      = 'root';
const DB_PASS      = '';                 // the MySQL password, if you set one
const APP_TIMEZONE = 'Asia/Manila';      // change if the shop is in another time zone
```

### 5. Open the app and create the Admin account
1. Open **http://localhost/shoe-inventory/** in the browser.
2. The first time, the page shows **First-time setup**. Enter:
   - your name;
   - a username (3–30 letters, numbers, dots, dashes or underscores);
   - a password of at least 8 characters, typed twice.
3. This becomes the **only Admin account**. A second Admin can never be created.
4. Sign in with it.

Photos are stored in `uploads\colorways` inside the app folder. That folder is created automatically the first time someone uploads a photo.

### 6. Add staff accounts
1. Go to **Users**, then **Add a staff account**.
2. New accounts are always **Staff** and start with view-only access. The **Permissions** page opens straight away.
3. Tick what the person may do, for example **Stock In** or **Add Shoes**, and click **Save changes**.
4. Ticking a permission also ticks what it needs. Changes apply on the person's next click.
5. To change it later, click **Manage permissions** on their row in **Users**.

---

## B. Moving your existing data to the other computer

Do part A steps 1–4 on the new computer first, **but skip `schema.sql` in step 3**. The backup below already contains the tables.

**On the old computer:**
1. Back up the database:
   ```
   C:\xampp\mysql\bin\mysqldump.exe -uroot --default-character-set=utf8mb4 --single-transaction --routines --triggers --result-file=C:\shoe-backup\shoe_inventory.sql shoe_inventory
   ```
   Create the folder `C:\shoe-backup` first.
2. Copy the photos folder `C:\xampp\htdocs\shoe-inventory\uploads\colorways` to `C:\shoe-backup\colorways`.
3. Copy `C:\shoe-backup` to the new computer, for example on a USB stick.

**On the new computer:**
1. Create an empty database, then load the backup into it:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE shoe_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"

   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/shoe-backup/shoe_inventory.sql"
   ```
2. Copy `C:\shoe-backup\colorways` to `C:\xampp\htdocs\shoe-inventory\uploads\colorways`.
3. Open **http://localhost/shoe-inventory/** and sign in with your usual account. The passwords move with the backup.

> The backup file contains the accounts' (encrypted) passwords. Keep it somewhere private, and do not share it.

---

## C. Upgrading a computer that still runs the OLD version

The old version has shoes without colorways and no Users permissions page. Upgrading it keeps every shoe, stock quantity and account. Each existing shoe gets a colorway called **"Original"**.

Nobody should use the app while you do this.

1. **Back up first** (same command as in part B):
   ```
   C:\xampp\mysql\bin\mysqldump.exe -uroot --default-character-set=utf8mb4 --single-transaction --routines --triggers --result-file=C:\shoe-backup\before_upgrade.sql shoe_inventory
   ```
   Also copy the whole app folder to `C:\shoe-backup\app-before-upgrade`.
2. **Copy the new `migrations` folder** into the app folder.
3. **Record a check of the current data.** This command is read-only and changes nothing:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/verify_inventory.sql" > C:\shoe-backup\check_before.txt
   ```
   Look at the last part of the output, the admin accounts. Only one Admin is allowed. If there are **two or more**, change the extra ones to Staff on the old Users page first. If you forget, step 4 stops with "more than one admin account" and changes nothing.
4. **Upgrade the database.** Keep `--abort-source-on-error`, so it stops at the first problem instead of continuing:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/001_colorways_permissions.sql"
   ```
5. **Run the check from step 3 again**, this time saving to `check_after.txt`. The two files must be **identical**, which means no shoe, stock row or account changed.
6. **Copy the new app files over the old ones, except `db.php`.** Keep your own `db.php`.
7. Open the app and sign in.
   - Existing **staff** accounts keep exactly what they could do before: view the inventory and change stock. Adjust them on the **Users** page.
   - The **Admin** has full access.

**If step 4 prints an `ERROR`, or the two check files differ, undo the upgrade:**
1. Create an empty database (see part B, "On the new computer", the first command).
2. Load `before_upgrade.sql` into it with the `source` command from part B.
3. Put back the app folder from `app-before-upgrade`.

If `shoe_inventory` still exists, delete it in phpMyAdmin before creating the empty one.

---

## Keeping it safe

- **Back up regularly.** Use the `mysqldump` command from part B with a date in the file name, and also copy the `uploads\colorways` folder.
- **Set a MySQL password.** XAMPP's `root` user has no password by default. Set one and put it in `db.php` (`DB_PASS`), especially if other people can reach the computer.
- **Don't upload your real password.** If you keep this project on GitHub and put a real password in `db.php`, do not commit and push that change. Keep it only on the shop computer.
- **Don't expose the app to the internet.** It is made for one shop computer or a trusted local network.
- **Use HTTPS if you can.** XAMPP includes a test certificate: open **https://localhost/shoe-inventory/** and accept the browser warning once. Sign-in cookies are then only ever sent over the encrypted connection.
- **Keep the Admin password private.** Give each staff member their own account with only the permissions they need.
- **Repeated wrong passwords lock an account.** After 5 wrong passwords in a row, it is locked for 5 minutes.

---

## Troubleshooting

| Problem | What to do |
|---|---|
| **"Database connection failed"** | Start **MySQL** in the XAMPP Control Panel. Check `DB_NAME`, `DB_USER` and `DB_PASS` in `db.php`. |
| **Error page or blank page** | Look at the newest lines of `C:\xampp\apache\logs\error.log`. |
| **`schema.sql` says tables already exist** | It only works on an empty database. To start over, delete the `shoe_inventory` database in phpMyAdmin (http://localhost/phpmyadmin), then repeat part A step 3. **This deletes all data.** |
| **Photos don't upload** | Photos must be JPEG, PNG, WebP or GIF and at most 5 MB. Check that `uploads\colorways` exists and is not read-only. |
| **A photo link is refused** | Use a direct link to a picture (it usually ends in `.jpg`, `.png` or `.webp`), not a link to a web page. |
| **"Too many failed attempts"** | Wait 5 minutes, then try again. |
| **"Access denied" for a staff member** | The Admin ticks the missing permission in **Users** → **Manage permissions**. |
| **Forgot the Admin password** | See below. |

**Resetting a forgotten Admin password:**

1. Make a password hash. Replace `NewPassword123` with the new password:
   ```
   C:\xampp\php\php.exe -r "echo password_hash('NewPassword123', PASSWORD_DEFAULT), PHP_EOL;"
   ```
2. Copy the long text it prints (it starts with `$2y$`).
3. In phpMyAdmin, open the `shoe_inventory` database, click **SQL** and run:
   ```sql
   UPDATE users SET password_hash = 'PASTE-THE-LONG-TEXT-HERE', failed_attempts = 0, locked_until = NULL WHERE role = 'admin';
   ```
4. Sign in with the new password.

---

## What the files are for

| File | Purpose |
|---|---|
| `index.php`, `login.php`, `logout.php` | Start page, sign-in (and the one-time Admin setup), sign-out |
| `admin.php` | **Inventory:** every model with its colorways, sizes and stock; Stock in, Stock out and Set count |
| `shoe.php` | **Shoes:** list of models, and a details page with colorway and size selection |
| `add-shoe.php` | **Add Shoe:** a model with one or more colorways, each with its own photo, sizes and starting stock |
| `colorway.php` | **Edit colorway:** name, description, photo, add or remove sizes, delete |
| `inventory-history.php` | **History:** every stock change |
| `users.php`, `user-permissions.php` | **Users** and each staff member's permission checklist (Admin only) |
| `db.php` | Database settings, the only file you edit |
| `auth.php`, `layout.php`, `inventory-lib.php`, `image-lib.php`, `permissions-lib.php` | Shared code used by the pages |
| `schema.sql` | Creates the tables for a **new** installation |
| `migrations\001_colorways_permissions.sql` | Upgrades an **old** installation (part C) |
| `migrations\verify_inventory.sql` | Read-only check of shoes, stock and accounts |
| `uploads\colorways\` | Shoe photos. Include it in backups. |
