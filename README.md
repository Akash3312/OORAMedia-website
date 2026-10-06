# Oora Media website

A static website for Oora Media, a local digital advertising network starting in Adelaide. It has an interactive screen map, enquiry forms and an admin dashboard.

## Files

| Path | What it is |
|---|---|
| `index.html` | The whole website (pages, styles and scripts) |
| `data/site-data.json` | The live screen listings and admin settings. The admin dashboard publishes to this file. |
| `images/screens/` | Screen photos uploaded from the admin dashboard |

## Managing screens

1. Open the site and go to **`#/admin`** (there is also an "Admin" link in the footer).
2. Sign in with the admin password.
3. Add, edit, deactivate or delete screens. Changes are saved on your device immediately.
4. Click **Publish to live site**. The changes are committed to this repository, and the live site updates in about a minute.

The first time you publish on a new device, the **Publishing** tab asks for a GitHub fine-grained access token. The token must cover this repository only, with **Contents: Read and write** permission. It is stored only in that browser.

## Hosting

Turn on GitHub Pages: **Settings → Pages → Deploy from a branch → `main` / root**.

## Not yet persistent

Enquiry forms and contact messages are held in memory only. Connect a form service (for example Formspree) before using the site with real customers.
