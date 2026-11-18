# Dazzling Footwear: Inventory & Size Availability System

<img src="assets/logo.png" alt="Dazzling Footwear logo" width="100">

**Dazzling Footwear** is a web app for a shoe store that keeps track of every shoe model and how many pairs are left in each size.

Each shoe model can come in several **colorways** (for example Nike Air Force 1 in *White* and *Black*). Each colorway has its own photo, its own sizes and its own stock.

- Sizes with 0 pairs are marked in red and labelled **OUT**.
- Sizes that are running low are marked in amber and labelled **LOW**.
- Every change to the stock is written to a history.
- The owner (Admin) decides exactly what each staff member is allowed to do.

Built with **PHP 8.1+ (PDO)**, **MariaDB** (included in XAMPP) and **Bootstrap 5**. It runs on your own computer (localhost).

**Repository:** https://github.com/brionesfrancisclark/shoe-inventory

---

## Features

- **Inventory** (`admin.php`): one row per shoe model.
  - Open a model to see each colorway with its photo and a grid of sizes showing the pairs left.
  - Summary cards, live search, category and stock filters, sorting, and 10 models per page.
  - **Edit stock** on a colorway lets you choose:
    - **Stock in (+)**: pairs arrived;
    - **Stock out (-)**: pairs sold, returned or damaged;
    - **Set count**: what you counted on the shelf.

    You can add an optional note, such as a delivery number.
- **Automatic grouping**: a model is recognised by **brand + model name**. Capitals and extra spaces don't matter.
  - Adding "nike / air force 1" when "Nike / Air Force 1" exists adds the new colorway to the existing model, so no duplicate model is created.
  - A colorway that already exists is refused, with a link to open the existing one.
  - If the category or price you typed differs from the existing model, you choose whether to keep or update it.
- **Add Shoe** (`add-shoe.php`): enter a model once, then one or more colorways. Each colorway has:
  - a photo, uploaded or taken from a link (links are checked before saving);
  - sizes, from tick boxes for US 7.0–11.0 or extra sizes you type in;
  - starting stock, with **Quick fill** to set every size at once.

  Everything is saved together, or not at all.
- **Edit colorway** (`colorway.php`):
  - rename it or change its description;
  - replace or remove its photo;
  - add sizes, or remove sizes that have 0 pairs;
  - delete the colorway (a model's last colorway can't be deleted).
- **Shoes** (`shoe.php`): every model as a card with its photo. Open one to see its colorways, then click a colorway to switch the photo and sizes, or click a size to see how many pairs are left.
- **History** (`inventory-history.php`): every stock change, showing who made it, when, what kind it was, the count before and after, and the note. You can filter by text, type, person and date. Entries stay even after a shoe is deleted.
- **Users & permissions** (`users.php`, `user-permissions.php`, Admin only):
  - create staff accounts and give each person a checklist of what they may do;
  - reset passwords, and turn accounts on or off.
- **Exactly one Admin**: a second Admin can never be created. The Admin can't be demoted, turned off or deleted. The database itself enforces this, not only the pages.

### Roles and permissions

The **Admin** can do everything. **Staff** can only do what the Admin ticked for them:

| What                                              | Admin | Staff needs this permission                  |
|---------------------------------------------------|:-----:|----------------------------------------------|
| See the inventory and pairs left                  |   ✓   | View Inventory                               |
| See which sizes are low / out of stock            |   ✓   | View Low Stock / View Out-of-Stock Items     |
| Stock in / Stock out / Set count                  |   ✓   | Stock In / Stock Out / Stock Adjustment      |
| See the stock history                             |   ✓   | View Inventory History                       |
| See shoes, colorways and photos (Shoes page)      |   ✓   | View Shoes / View Colorways                  |
| Add new shoe models                               |   ✓   | Add Shoes                                    |
| Add colorways to an existing model                |   ✓   | Add Colorways                                |
| Change a model's name, brand, category or price   |   ✓   | Edit Shoes                                   |
| Rename a colorway, add or remove its sizes        |   ✓   | Edit Colorways                               |
| Add a photo when adding a shoe or colorway        |   ✓   | Upload Shoe Images                           |
| Replace or remove a colorway's photo              |   ✓   | Change Shoe Images                           |
| Delete a colorway / a whole model                 |   ✓   | Delete Colorways / Delete Shoes              |
| Manage users and permissions                      |   ✓   | *(Admin only)*                               |

- **New staff accounts start view-only:** View Shoes, View Colorways, View Inventory, View Low Stock and View Out-of-Stock Items.
- **Prerequisites are ticked for you:** ticking a permission also ticks what it needs. For example, Stock In also ticks View Inventory.
- **The server checks every request,** so hiding a button is never the only protection.

---

## Project structure

```
shoe-inventory/
├── README.md               This file
├── schema.sql              Creates all the tables for a NEW installation
├── migrations/
│   ├── 001_colorways_permissions.sql   Upgrades an OLD installation (see "Upgrading an old copy")
│   └── verify_inventory.sql            Read-only check of shoes, stock and accounts
├── assets/
│   ├── logo.png            The Dazzling Footwear logo (menu bar and sign-in page)
│   └── favicon.png         The small icon in the browser tab
├── db.php                  Database settings (the only file you normally edit) + small helpers
├── auth.php                Sign-in checks, permissions, security headers
├── layout.php              Shared page header, menu, messages
├── index.php               Start page (sends you to sign-in or to your first page)
├── login.php / logout.php  Sign in (+ the one-time "create the Admin" form) / sign out
├── admin.php               Inventory
├── shoe.php                Shoes list and shoe details
├── add-shoe.php            Add a shoe model with its colorways
├── colorway.php            Edit or delete a colorway
├── inventory-history.php   Stock history
├── users.php               Users (Admin only)
├── user-permissions.php    A staff member's permission checklist (Admin only)
├── inventory-lib.php       Shared code: shoes, colorways, sizes, stock, history
├── image-lib.php           Shared code: photo uploads and links
└── permissions-lib.php     Shared code: permission checklists
```

Uploaded photos are stored in `uploads/colorways/`. The folder is created automatically, and it is **not** stored on GitHub (it holds shop data, not code).

---

## Requirements

| Item       | Version |
|------------|---------|
| PHP        | **8.1 or newer**, with the `pdo_mysql`, `fileinfo` and `mbstring` extensions (all switched on in XAMPP) |
| Database   | **MariaDB** (the database that comes with XAMPP; tested with 10.11). Plain MySQL is not supported. |
| Web server | Apache (XAMPP) or PHP's built-in server |
| Browser    | Edge, Chrome or Firefox |
| Internet   | Needed when pages load: Bootstrap and its icons come from a CDN (see *Working offline*) |

The easiest way on Windows is **[XAMPP](https://www.apachefriends.org/)**, which bundles Apache, PHP, MariaDB and phpMyAdmin. These steps were tested on Windows with XAMPP (PHP 8.5, MariaDB 10.11).

---

## Setup (XAMPP)

### 1. Install and start XAMPP
1. Download XAMPP from https://www.apachefriends.org/ and install it. Choose a version with PHP 8.1 or newer, and keep the default folder `C:\xampp`.
2. Open the **XAMPP Control Panel**.
3. Click **Start** next to **Apache** and next to **MySQL**. Both names should turn green.

### 2. Get the project from GitHub
The files must end up in a folder named **`shoe-inventory`** inside XAMPP's `htdocs` folder:

- Windows: `C:\xampp\htdocs\shoe-inventory\`
- macOS: `/Applications/XAMPP/htdocs/shoe-inventory/`
- Linux: `/opt/lampp/htdocs/shoe-inventory/`

**Option A: clone with Git.**
1. Install [Git](https://git-scm.com/downloads) if you don't have it. To check, run `git --version`.
2. Open **Command Prompt**: press the Windows key, type `cmd` and press Enter.
3. Run:

```
cd C:\xampp\htdocs
git clone https://github.com/brionesfrancisclark/shoe-inventory.git
```

This creates `C:\xampp\htdocs\shoe-inventory\` with all the files.

**Option B: download a ZIP (no Git needed).**
1. Open https://github.com/brionesfrancisclark/shoe-inventory (no GitHub account needed)
2. Click the green **Code** button, then **Download ZIP**.
3. Extract it into `C:\xampp\htdocs`. GitHub names the folder `shoe-inventory-main`, so **rename it to `shoe-inventory`**. Otherwise the addresses below won't work.

**Check:** `C:\xampp\htdocs\shoe-inventory\` must *directly* contain `login.php`, `admin.php`, `db.php`, `schema.sql` and the other files. It must not contain another folder with the files inside.

### 3. Create the database
1. Open **http://localhost/phpmyadmin** in your browser.
2. On the left, click **New**.
   - Database name: `shoe_inventory`
   - Collation: `utf8mb4_general_ci`

   Click **Create**.
3. Make sure the new `shoe_inventory` database is selected on the left. Then open the **Import** tab at the top.
4. Click **Choose File** and pick **`schema.sql`** from `C:\xampp\htdocs\shoe-inventory\`.
5. Scroll down and click **Import** (in some versions **Go**).
6. You should see a green success message, and these **8 tables** on the left:
   - `permissions`
   - `schema_migrations`
   - `shoe_colorways`
   - `shoe_sizes`
   - `shoes`
   - `stock_movements`
   - `user_permissions`
   - `users`

`schema.sql` creates **everything**, so there is nothing else to paste. It only works on an **empty** database. If the tables already exist, it stops with "Stopped: tables already exist" and changes nothing.

<details>
<summary>Alternative: create the database from Command Prompt instead of phpMyAdmin</summary>

```
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE shoe_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"

C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/schema.sql"
```
If it worked, both commands print **nothing**. If your MySQL `root` user has a password, add `-p` after `-uroot`.
</details>

### 4. Check the database settings
Open `db.php` in a text editor (for example Notepad) and check the values at the top. The XAMPP defaults already work:

```php
const DB_HOST      = 'localhost';
const DB_NAME      = 'shoe_inventory';
const DB_USER      = 'root';
const DB_PASS      = '';                 // XAMPP's root user has no password by default
const APP_TIMEZONE = 'Asia/Manila';      // change if the store is in another time zone
```

### 5. Open the app and create the Admin
1. Go to **http://localhost/shoe-inventory/**
2. No accounts exist yet, so you'll see **First-time setup**. Enter:
   - your **name**;
   - a **username**: 3–30 letters, numbers, dots, dashes or underscores;
   - a **password** of at least 8 characters, typed **twice**.
3. This creates the **only Admin account**. Sign in with it.

### 6. Add your staff
1. Open **Users** and click **Add a staff account**. Enter their name, username and a temporary password.
2. Their **Permissions** page opens automatically. Tick what they may do, then click **Save changes**.
3. To change it later, click **Manage permissions** on their row in **Users**.

Some common setups (the view permissions they need are ticked automatically):

| Job                      | Tick                                                            |
|--------------------------|-----------------------------------------------------------------|
| Sales / cashier          | Stock Out                                                       |
| Receiving deliveries     | Stock In                                                        |
| Stock counting           | Stock Adjustment, View Inventory History                        |
| Product manager          | Add Shoes, Add Colorways, Edit Shoes, Edit Colorways, Upload Shoe Images, Change Shoe Images |

---

## Keeping your copy up to date

If you cloned with Git, get the latest version at any time:

```
cd C:\xampp\htdocs\shoe-inventory
git pull
```

If you changed `db.php` (for example to add your database password) and Git complains about local changes, run these three commands in order. They put your own changes back on top:

```
git stash
git pull
git stash pop
```

To send your own changes to GitHub, for example from VS Code or Command Prompt:

```
git add .
git commit -m "Describe what you changed"
git push
```

---

## Moving the app and its data to another computer

The code comes from GitHub, but your **shoes, stock, accounts and photos** are not on GitHub. Move them like this.

**On the old computer**
1. Create a folder `C:\shoe-backup`.
2. In Command Prompt, run:
   ```
   C:\xampp\mysql\bin\mysqldump.exe -uroot --default-character-set=utf8mb4 --single-transaction --routines --triggers --result-file=C:\shoe-backup\shoe_inventory.sql shoe_inventory
   ```
3. Copy the photo folder `C:\xampp\htdocs\shoe-inventory\uploads\colorways` into `C:\shoe-backup\`.
4. Copy `C:\shoe-backup` to the new computer, for example on a USB stick.

**On the new computer**
1. Do setup steps 1, 2 and 4. In step 3, only **create** the empty database. **Do not import `schema.sql`.**
2. In Command Prompt, load the backup:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/shoe-backup/shoe_inventory.sql"
   ```
3. Copy `C:\shoe-backup\colorways` to `C:\xampp\htdocs\shoe-inventory\uploads\colorways`.
4. Open http://localhost/shoe-inventory/ and sign in as usual. Passwords move with the backup.

> The backup contains the accounts' (encrypted) passwords. Keep it private, and never upload it to GitHub.

---

## Upgrading an old copy

The old version has no colorways and no permission checklists. This upgrade keeps every shoe, stock quantity and account. Each existing shoe gets one colorway called **"Original"**, and existing staff keep exactly what they could do before (view the inventory and change stock).

Nobody should use the app while you do this. Run each command in Command Prompt.

1. **Back up first:**
   ```
   C:\xampp\mysql\bin\mysqldump.exe -uroot --default-character-set=utf8mb4 --single-transaction --routines --triggers --result-file=C:\shoe-backup\before_upgrade.sql shoe_inventory
   ```
   Also copy the whole `shoe-inventory` folder to `C:\shoe-backup\app-before-upgrade`.
2. **Get the new files** (`git pull`, or the ZIP), but keep your own `db.php`.
3. **Save a check of the data now.** This is read-only:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/verify_inventory.sql" > C:\shoe-backup\check_before.txt
   ```
4. **Upgrade the database:**
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 --abort-source-on-error shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/001_colorways_permissions.sql"
   ```
   - Only one Admin is allowed. If the old copy has more, this stops with "more than one admin account" and changes nothing. Make the extra ones Staff on the old Users page, then run it again.
5. **Check again and compare:**
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 shoe_inventory -e "source C:/xampp/htdocs/shoe-inventory/migrations/verify_inventory.sql" > C:\shoe-backup\check_after.txt

   fc C:\shoe-backup\check_before.txt C:\shoe-backup\check_after.txt
   ```
   It must say **"FC: no differences encountered"**. That means no shoe, stock row or account changed.
6. Open the app and sign in.

**If step 4 shows an `ERROR`, or step 5 finds differences, undo the upgrade:**
1. In phpMyAdmin, delete the `shoe_inventory` database.
2. Create it again, empty.
3. Load `before_upgrade.sql` into it, the same way as when moving the data.
4. Put back the folder from `app-before-upgrade`.

---

## Alternative: PHP's built-in server

If you'd rather not use Apache, MariaDB still has to be running. Then:

```
cd C:\xampp\htdocs\shoe-inventory
C:\xampp\php\php.exe -S localhost:8000
```

Then open **http://localhost:8000/**. Keep the Command Prompt window open while you use the app, and press Ctrl+C to stop it.

---

## Using the system

**Add a shoe**
1. Open **Add Shoe** and enter the model name, brand, category and price.
2. Fill in the first colorway:
   - its name;
   - a photo (optional);
   - its sizes: tick them and enter the starting pairs. Use **Quick fill** to set every ticked size at once, and **+ Add another size** for sizes like 6.5 or 12.
3. Click **+ Add another colorway** for more colorways.
4. Click **Save shoe**.

Ticked sizes left at 0 are saved too, so they show as out of stock. If the model already exists, the page tells you as you type, and your colorways are added to it.

**Update stock**
1. On **Inventory**, open a model, then click **Edit stock** on a colorway.
2. Choose **Stock in (+)**, **Stock out (-)** or **Set count**.
3. Enter the numbers, using the − / + buttons or by typing. Add a note if you like.
4. Click **Save**.

Each size shows what it will become before you save. Stock can never go below 0.

**Edit a colorway**: open a model and click **Edit colorway**. Change what you need and click **Save changes**. To remove a size, it must have 0 pairs first: use Stock out or Set count.

**Find problems fast**: click the red or amber summary card, or use the **Stock** filter, to see only models with out-of-stock or low-stock sizes.

**Look at a shoe**: open **Shoes**, click a model, then click a colorway and a size.

**Check who changed what**: open **History**. On a colorway, the **History** link shows only that colorway's changes.

**Delete**: open a model on **Inventory**.
- **Delete colorway** removes one colorway.
- **Delete shoe** removes the model with all its colorways.

Both ask for confirmation first. The history keeps a record.

---

## Configuration

| What                                   | Where                                          | Default |
|----------------------------------------|------------------------------------------------|---------|
| Database settings                      | `db.php`                                       | `root`, no password, database `shoe_inventory` |
| Store name                             | `layout.php` (menu bar and tab title) and `login.php` (sign-in page) | Dazzling Footwear |
| Logo / tab icon                        | Replace `assets/logo.png` / `assets/favicon.png` (keep the file names) | Two-shoe logo |
| Time zone                              | `APP_TIMEZONE` in `db.php`                     | `Asia/Manila` |
| Low-stock threshold                    | `LOW_STOCK_LIMIT` in `inventory-lib.php`       | 3 pairs (1–3 = low, 0 = out) |
| Standard sizes on the forms            | `STANDARD_SIZES` in `inventory-lib.php`        | US 7.0–11.0 in half sizes |
| Most pairs in one size                 | `MAX_QTY` in `inventory-lib.php`               | 100,000 |
| Colorways per Add Shoe save            | `MAX_COLORWAYS_PER_FORM` in `inventory-lib.php`| 20 |
| Photo size limit                       | `IMAGE_MAX_BYTES` in `image-lib.php`           | 5 MB (JPEG, PNG, WebP, GIF) |
| Models per page on Inventory           | `PER_PAGE` in the script of `admin.php`        | 10 |
| History entries per page               | `HISTORY_PER_PAGE` in `inventory-history.php`  | 50 |
| Auto sign-out when idle                | `IDLE_TIMEOUT` in `auth.php`                   | 3600 s (1 hour) |
| Wrong passwords before a lock          | `MAX_FAILED` in `auth.php`                     | 5 |
| Lock duration                          | `LOCK_SECONDS` in `auth.php`                   | 300 s (5 minutes) |
| Permissions new staff start with       | `default_for_new_staff` column of the `permissions` table | the 5 "View" permissions |

---

## Security notes

- All database access uses **PDO prepared statements** (real server-side prepares).
- Passwords are stored with `password_hash()`. Plain-text passwords are never saved.
- Every form carries a **CSRF token**, and everything shown on a page is HTML-escaped. A name or note containing code is shown as plain text, never run.
- Sign-in errors don't reveal whether a username exists. After **5 wrong passwords** the account is locked for 5 minutes.
- Permissions are re-checked on every click, so ticking or unticking a permission (or turning an account off) takes effect immediately.
- **Exactly one Admin.** Staff can never be promoted, and the Admin can't be demoted, turned off or deleted. A unique key and two triggers in the database enforce this, even against hand-made requests.
- **Photo uploads** are checked by their real content: only JPEG, PNG, WebP or GIF, at most 5 MB. Each is saved under a new random name, so an uploaded file can never run as code. Photo links must be `http(s)` web addresses.
- Pages can't be shown inside other websites' frames (clickjacking protection). The sign-in cookie is `HttpOnly` and `SameSite`, and also `Secure` when you use HTTPS.
- **Never commit real credentials.** `db.php` in the repository contains only the XAMPP defaults (`root`, empty password). If you set a real database password locally, don't push it to GitHub: this repository is **public**, so anyone can read what you push. Also never commit database backups, photos or exports with real accounts or business data.
- This is built for **one store computer or a trusted local network**. Before putting it on a public server, do all of these:
  - use HTTPS;
  - set a strong MySQL password;
  - create a separate database user instead of `root`;
  - turn off PHP error display.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| "This site can't be reached" | Start **Apache** in the XAMPP Control Panel. |
| "Not Found" / "Object not found!" | The folder must be exactly `C:\xampp\htdocs\shoe-inventory`, with `login.php` directly inside it (not inside `shoe-inventory-main`). |
| "Database connection failed. Check db.php credentials." | Start **MySQL** in XAMPP. Check `DB_NAME`, `DB_USER` and `DB_PASS` in `db.php`. |
| 'The "users" table is missing. Run schema.sql…' | Import `schema.sql` into the `shoe_inventory` database (setup step 3). |
| Import says "Stopped: tables already exist" | `schema.sql` is only for an **empty** database. For an old database, see *Upgrading an old copy*. To start completely fresh, delete the `shoe_inventory` database in phpMyAdmin (**this deletes all data**), create it again and import. |
| Apache won't start (port 80 or 443 in use) | Another program (Skype, IIS, VMware) is using the port. Close it, or change Apache's port in XAMPP → Config → `httpd.conf` and then use `http://localhost:8080/shoe-inventory/`. |
| MySQL won't start (port 3306 in use) | Another database program is running. Stop it in Windows **Services**, then click Start again. |
| `could not find driver` | In `C:\xampp\php\php.ini` remove the `;` in front of `extension=pdo_mysql`, then restart Apache. |
| `Class "finfo" not found` when uploading a photo | In `php.ini` remove the `;` in front of `extension=fileinfo`, then restart Apache. |
| A photo is refused | Only JPEG, PNG, WebP or GIF, at most 5 MB. |
| "This link does not show a picture" | Use a direct link to an image (it usually ends in `.jpg`, `.png` or `.webp`), not a link to a web page, or upload the photo instead. |
| Page looks unstyled, or icons are missing | Bootstrap and its icons load from the internet (jsDelivr CDN). Check the connection, or see *Working offline*. |
| "Security token invalid" | Your session expired or cookies are blocked. Reload the page and try again. |
| "Too many failed attempts" | Wait 5 minutes. For a staff account, the Admin can also clear it right away: **Users** → edit the account → **Save**. |
| "Access denied" | That account doesn't have the needed permission. The page names it, and the Admin can tick it in **Users → Manage permissions**. |
| Forgot the Admin password | See below. (Deleting the Admin is blocked on purpose.) |
| Blank white page or "Something went wrong" | Look at the newest lines of Apache's `error.log` (XAMPP → Apache → **Logs**). |

**Resetting a forgotten Admin password**
1. In Command Prompt, run this, replacing `NewPassword123` with the new password:
   ```
   C:\xampp\php\php.exe -r "echo password_hash('NewPassword123', PASSWORD_DEFAULT), PHP_EOL;"
   ```
2. Copy the long text it prints. It starts with `$2y$`.
3. In phpMyAdmin, open the `shoe_inventory` database, click the **SQL** tab, paste this (with your text) and click **Go**:
   ```sql
   UPDATE users SET password_hash = 'PASTE-THE-LONG-TEXT-HERE', failed_attempts = 0, locked_until = NULL WHERE role = 'admin';
   ```
4. Sign in with the new password.

### Working offline
1. Download Bootstrap **5.3.3**. Put `bootstrap.min.css` and `bootstrap.bundle.min.js` in an `assets/` folder inside the project.
2. Download Bootstrap Icons **1.11.3**. Copy its `font/` folder to `assets/bootstrap-icons/`. It holds `bootstrap-icons.min.css` and a `fonts/` folder.
3. In `layout.php`, change the three `cdn.jsdelivr.net` addresses to:
   - `assets/bootstrap.min.css`
   - `assets/bootstrap-icons/bootstrap-icons.min.css`
   - `assets/bootstrap.bundle.min.js`

---

## Backup

Back up regularly, for example once a day. A backup has **two parts**:

1. **The database.**
   - In phpMyAdmin: select `shoe_inventory` → **Export** → **Go**.
   - Or in Command Prompt (change the date in the file name):
     ```
     C:\xampp\mysql\bin\mysqldump.exe -uroot --default-character-set=utf8mb4 --single-transaction --routines --triggers --result-file=C:\shoe-backup\shoe_inventory_2026-11-17.sql shoe_inventory
     ```
2. **The photos:** copy the folder `C:\xampp\htdocs\shoe-inventory\uploads\colorways`.

To restore a backup, follow *Moving the app and its data to another computer*.

---

## Possible next steps

- A public, read-only **size availability page** for customers.
- **CSV / Excel export** of the current stock and the history.
- **Barcode scanning** for Stock in / Stock out.
- Daily **sales and stock reports**.
- **Low-stock alerts** by email.
