# Enquiry emails: Hostinger setup

Every website enquiry (advertiser enquiries, Screen Partner applications and contact messages) is handled by `api/index.php` on your own Hostinger hosting. For each one it:

1. **saves** the enquiry in a MySQL database on your hosting
2. **emails** it to admin@ooramedia.com, sent from the admin@ooramedia.com mailbox through Hostinger's mail server, with the visitor set as reply-to
3. shows it in the **Admin Dashboard**, with its email status

Your domain already has SPF, DKIM and DMARC set up for Hostinger mail, so **no DNS changes are needed**.

The code deploys automatically from GitHub. The only thing that is *not* in GitHub is a private settings file with your passwords, which you create once in hPanel.

---

## One-time setup (about 10 minutes)

### Step 1: Check the code has deployed

After the latest push, open **https://ooramedia.com/api/index.php?route=health**.

It should show `{"ok":false,"configured":false,…}`. That means the service is installed and waiting for its settings. If you get a "not found" page instead, the deploy hasn't happened yet; in hPanel, check **Websites → Manage → Advanced → GIT**.

### Step 2: Create the database

1. Go to **hPanel → Websites → Manage (ooramedia.com) → Databases → MySQL Databases**.
2. Fill in **Database name** (`oora`), **Username** (`oora`) and a **strong password**, then click **Create**.
3. Hostinger adds a prefix. Note the **full** database name and username shown in the list, for example `u123456789_oora`. The host is `localhost`.

The enquiries table is created automatically the first time the service runs.

### Step 3: Create the private settings file

1. Go to **hPanel → Files → File Manager**.
2. Open the folder that **contains** `public_html`. This is usually `domains/ooramedia.com/`, one level **above** `public_html`. The file must **not** go inside `public_html`.
3. Create a new file named **`oora-config.php`**.
4. Copy the contents of [`hosting/oora-config.sample.php`](oora-config.sample.php) into it. The template deliberately has no comments: Hostinger's firewall can block saving files that contain folder paths or `<…>` text, and shows a `403 Forbidden` error. Then fill in:

| Setting | What to put |
|---|---|
| `db` → `YOUR_DB_NAME`, `YOUR_DB_USER`, `YOUR_DB_PASSWORD` | The full database name, username and password from step 2. Keep `host=localhost`. |
| `YOUR_MAILBOX_PASSWORD` | The password for the **admin@ooramedia.com** mailbox, the same one you use for Hostinger Webmail. |
| `notify_to` | Where notifications go. Leave as `admin@ooramedia.com`, or add more addresses separated by commas. |
| `admin_token` | Leave a placeholder for now; step 5 generates the value. |
| `YOUR_CRON_KEY`, `YOUR_IP_SALT` | Any long random text, different for each. You can click **Generate a new token** (step 5) twice and copy each code. |

5. Save the file. Reload the health link from step 1: it should now show `"ok":true,"configured":true,"email":true`.

If you ever change the mailbox password, update `smtp` → `pass` in this file too.

### Step 4: Turn on automatic retries (recommended)

If Hostinger's mail server is ever briefly unavailable, the enquiry is still saved. This job re-sends the email automatically.

1. Go to **hPanel → Advanced → Cron Jobs**.
2. Choose **Custom**. Your `u123…` username appears in File Manager paths and under **hPanel → Account → Account Information**. Enter:
   ```
   /usr/bin/php /home/u123456789/domains/ooramedia.com/public_html/api/cron.php
   ```
3. Set it to run **every 10 minutes**: minute `*/10`, everything else `*`.

Without this, a failed email just shows **Retrying** in the dashboard until you press **Send now**.

### Step 5: Connect the Admin Dashboard and switch it on

1. Open **https://ooramedia.com/#/admin**, sign in, and go to **Publishing → Enquiry emails**.
2. Click **Generate a new token** and copy it.
3. Paste it into `admin_token` in `oora-config.php` (File Manager) and save the file.
4. Back in the dashboard, click **Save & check connection**. It should say *Service found… Admin token accepted*.
5. Click **Send a test email** and check admin@ooramedia.com. The first time, also check the spam folder.
6. Click **Turn enquiry emails on**, then **Publish to live site**.

About a minute later, every form on the website uses the service. Submit a test enquiry on the live **Contact** page to confirm.

On other devices (phone, laptop), paste the same admin token in **Publishing** to see enquiries in the dashboard there.

---

## Day to day

- **New enquiries:** arrive by email with a reference number (for example `OM-ADV-261015-B7QR`). Press **Reply** to answer the visitor directly.
- **Admin Dashboard:** every enquiry appears with its email status:
  - **Emailed**
  - **Retrying**: a temporary problem; the cron job will retry
  - **Not sent**: a settings problem, such as a wrong mailbox password. Fix it, then press **Send now**.
- **Errors:** logged in the hosting error log (hPanel → Advanced), or in `log_file` if you set one. Logs contain reference numbers and error messages only, never visitor details.
- **Change the destination address:** edit `notify_to` in `oora-config.php`. No code change or deploy is needed.

## Troubleshooting

| You see | Fix |
|---|---|
| "Mail server rejected the mailbox password" | Check `smtp` → `user`/`pass` in oora-config.php match the mailbox login. |
| "Could not connect to the mail server" | Keep `smtp.hostinger.com`, port `465`, `ssl`. Retries happen automatically. |
| Health shows `"configured":false` | oora-config.php is missing, in the wrong folder, or has a typo. It must be next to `public_html`, not inside it. |
| Health shows a database error | Check the `db` settings use the **full** prefixed names. |
| File Manager shows `403 Forbidden` when saving | Hostinger's firewall disliked the text. Use the template exactly as provided, without extra comments. If a password contains `<` or `>`, create the file in Notepad and **Upload** it instead. |
| Visitors see "not set up yet" | Enquiry emails were switched on before setup was finished. Finish step 3, or switch it off and publish. |

PHP 8.0 or newer is required (hPanel → Advanced → PHP Configuration). Hostinger's default is fine.

## Security

- Passwords live only in `oora-config.php`, outside the public website folder. It is listed in `.gitignore`, so it can't be committed by accident.
- The admin token protects the dashboard's access to enquiries. It is stored only in your browser on each device.
- Spam protection includes:
  - server-side validation of every field
  - a hidden honeypot field
  - a minimum time to fill the form
  - a limit of 8 enquiries per 10 minutes per connection
  - only ooramedia.com can submit
- Each form fill carries a unique key, so double-clicks and refreshes never create duplicate enquiries or emails.

## Developer notes

- **Code:** `api/index.php` is the entry point, `api/lib/oora.php` the logic, `api/lib/smtp.php` the email sending, and `api/cron.php` the retry job.
- **Tests:** in `hosting/tests/`:
  - `fake-smtp.mjs` is a fake SSL mail server.
  - `test-config.php` sets up SQLite with no real credentials.
  - `run-tests.mjs` is the end-to-end suite.
- **Running the tests:**
  1. Start `node hosting/tests/fake-smtp.mjs cert.pem key.pem`.
  2. Start `php -S localhost:8788 -t .` with `OORA_CONFIG=hosting/tests/test-config.php`.
  3. Run `node hosting/tests/run-tests.mjs`.
