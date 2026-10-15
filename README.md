# Oora Media website

A static website for Oora Media, a local digital advertising network starting in Adelaide. It has an interactive screen map, enquiry forms and an admin dashboard.

## Files

| Path | What it is |
|---|---|
| `index.html` | The whole website (pages, styles and scripts) |
| `data/site-data.json` | The live screen listings and admin settings. The admin dashboard publishes to this file. |
| `images/screens/` | Screen photos uploaded from the admin dashboard |
| `api/` | Enquiry service (PHP): saves enquiries and emails them |
| `hosting/` | Setup guide, settings template and tests for the enquiry service (blocked from public access) |

## Managing screens

1. Open the site and go to **`#/admin`** (there is also an "Admin" link in the footer).
2. Sign in with the admin password.
3. Add, edit, deactivate or delete screens. Changes are saved on your device immediately.
4. Click **Publish to live site**. The changes are committed to this repository, and the live site updates in about a minute.

The first time you publish on a new device, the **Publishing** tab asks for a GitHub fine-grained access token. The token must cover this repository only, with **Contents: Read and write** permission. It is stored only in that browser.

## Opening hours format

Each screen stores its hours as a structured `schedule`: one key per day (`mon` to `sun`), each holding a list of `[open, close]` periods in 24-hour `HH:MM`. An empty list means closed, and `24:00` means midnight at the end of the day.

```json
"schedule": {
  "mon": [["08:00", "12:00"], ["14:00", "18:00"]],
  "tue": [],
  "wed": [["09:00", "18:00"]]
}
```

Older screens that only have text `days` / `hours` fields still display correctly. They are converted to a `schedule` the first time their hours are edited in the admin dashboard.

## Hosting

The site is hosted on Hostinger at https://ooramedia.com. Hostinger deploys automatically from this GitHub repository (`main` branch) whenever it changes.

## Enquiries and email notifications

Website enquiries are handled by `api/index.php` on the Hostinger hosting. It saves each enquiry in MySQL and emails it to admin@ooramedia.com from the Hostinger mailbox. One-time setup (a private settings file and a database) is described in [hosting/README.md](hosting/README.md). Until enquiry emails are switched on in **Admin → Publishing**, the forms only keep enquiries in the visitor's browser.
