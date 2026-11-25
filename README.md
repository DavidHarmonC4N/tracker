# Offgrid Comms Tracker

A public catalog of MeshCore and Meshtastic radios from U.S. and international sellers, with a private admin page, built for PHP shared hosting. The catalog comes with starter listings from the November 24, 2026 research. Historical prices are visible immediately. Live stock appears once an automated or manual check verifies it.

No external database, package installation, or paid stock API is required. The tracker stores its settings and results in a private folder on your host.

## What lives where

There are two places: this **GitHub repository** (the master copy of the code, plus the automation) and your **web host** (the live site and everything private). Keep them straight with this table.

| What | GitHub repository | Web host |
|---|---|---|
| Site code: `index.html`, `admin.php`, `catalog.php`, `feed.php`, `submit.php`, `visit.php`, `ingest.php`, `check.php`, `includes/`, `assets/`, `.well-known/` | Master copy | Live copy. Put there by the automatic deploy or by a manual upload. |
| `.htaccess` | Plain, working version | Live copy. Edited by hand. The deploy never overwrites it. |
| `.htaccess.hardened` | Optional stricter rules | Not used |
| `config.php` | **Blank template only** | **Your real values. Never leaves the host.** |
| Private data folder (`tracker.json`, `visits.json`, `ratelimit.json`) | Never | Outside the web root |
| `.github/` (workflows, safety check) and `runner/` | Yes | Not needed |
| `README.md` | Yes | Not needed. If uploaded, `.htaccess` blocks it. |
| Passwords and tokens | Never in files | In the host's `config.php`, and in GitHub **repository secrets** |

The repository is public. Anything committed to it is public, so a real password or key committed by mistake is leaked. A safety check guards against that (see [Security notes](#security-notes)).

### Where each secret goes

**GitHub repository secrets.** Add them under **Settings → Secrets and variables → Actions → Repository secrets → New repository secret**. They must be *repository* secrets, not *environment* secrets and not *variables*, because the runner workflow reads them too.

| Secret | Used by | Value |
|---|---|---|
| `OFFGRID_URL` | U.S. runner, deploy live check | Address of the site folder, ending in a slash, for example `https://YOUR-DOMAIN/` |
| `OFFGRID_TOKEN` | U.S. runner | The same random key you put in `ingest_token` on the host |
| `DEPLOY_HOST` | Deploy | FTP server name from your host |
| `DEPLOY_USER` | Deploy | The separate FTP account's user name |
| `DEPLOY_PASSWORD` | Deploy | That account's password |
| `DEPLOY_DIR` | Deploy | Folder to upload into, ending in a slash (`/` if the account is limited to the site folder) |
| `DEPLOY_PROTOCOL` | Deploy (optional) | `ftps` (default), `ftp`, or `ftps-legacy` |

**The host's `config.php`.** These stay on the host only. See the [settings reference](#settings-reference-configphp) for every key. The ones that matter most: `data_dir`, `setup_key` (until you set the admin password), `ingest_token` (must equal `OFFGRID_TOKEN`), and `github_token` (a token you create on GitHub; see [Start the runner from your site](#start-the-runner-from-your-site-recommended)).

## Set up the host (once)

1. In your host's file manager, select PHP **8.3 or newer**. Enable **cURL** and **DOM**. You also need HTTPS and outbound HTTPS access.
2. Put the site files in a dedicated web folder, for example `public_html/offgrid/`. For the very first upload, use **Code → Download ZIP** on the GitHub repository page, extract it, and upload the site files from the table above. The `.github` and `runner` folders and `README.md` are not needed. Keep the included `.htaccess` files if you use Apache or LiteSpeed. After the first upload you can switch to the [automatic deploy](#automatic-deploy-from-github).
3. In your hosting account's home directory, create a writable folder named `offgrid-private` **outside** every public web root. Use permissions `700` if PHP runs as your hosting account. Never put this folder inside `public_html`. If your host uses more than one domain, keep it outside every domain's document root.
4. Edit the uploaded `config.php` **on the host**. Set `data_dir` to the full folder path, for example `/home/ACCOUNT/offgrid-private`, and `setup_key` to a unique random key of at least 32 characters from your password manager. If the key contains an apostrophe or backslash, escape it in the PHP string or choose an alphanumeric key. Also set `base_url` to the public address of the folder.
5. Open `https://YOUR-DOMAIN/offgrid/admin.php`. Enter the setup key and make an administrator password. You can then remove the setup key from `config.php`. The public page is `https://YOUR-DOMAIN/offgrid/index.html`.
6. Choose how stock gets checked (you can use both):
   - **U.S. runner (recommended):** checks run on GitHub's U.S. servers. See [U.S. checker](#us-checker-github-actions). No cron job is needed.
   - **This server's cron:** add a scheduled job in your hosting panel. In cPanel open **Advanced → Cron Jobs**, set Minute to `*/10` and the other fields to `*`, and use your host's PHP path:

     ```sh
     /usr/local/bin/php /home/ACCOUNT/public_html/offgrid/check.php
     ```

     If your host publishes a version-specific path, use that one. If it provides a terminal, run the same command once to see a listing count. Hosts outside the U.S. may be refused by some stores, which is what the runner is for.

Back up `tracker.json` and keep it private. When updating code, always keep your existing `config.php` and private data. The starter catalog is only used to initialize a new installation.

## Set up GitHub (once)

1. Keep the repository **public**: public repositories get unlimited free Actions minutes. For a private repository, change the schedule in `.github/workflows/check.yml` to `*/30` to stay inside the free 2,000 minutes.
2. Add the repository secrets from the table above for the features you want:
   - U.S. runner: `OFFGRID_URL` and `OFFGRID_TOKEN`.
   - Automatic deploy: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PASSWORD`, `DEPLOY_DIR` (and optionally `DEPLOY_PROTOCOL`).
3. Optional, to stop secrets from being committed by accident on your own computer, turn on the commit check once per clone: `git config core.hooksPath .githooks`.
4. In the **Actions** tab, enable workflows.

## Updating the site

**Automatic (after you set up the deploy below):** push to `main` and the changed files go live. Nothing to upload.

**By hand:** download the repository (**Code → Download ZIP**) or take a zip of the changed files, and upload them over the live site. Always keep your live `config.php`, `.htaccess` and private folder. Existing listings, stock readings, and the administrator password carry over. New starter listings added in an update are added once, so deleting one in Admin sticks. When an update adds a setting, add the new line from this repository's `config.php` to your live one.

When several files depend on each other, upload them all together. A new `admin.php`, for example, needs the matching `includes/app.php`.

## Automatic deploy from GitHub

`.github/workflows/deploy.yml` sends the files on `main` to your host by FTP, so changes go live without a manual upload. It does nothing until the `DEPLOY_*` secrets exist, and it never touches `config.php`, `.htaccess` or your private data folder.

What it does on every push to `main` (or when you start it by hand):

1. Stops if `config.php` holds real values or any file looks like it contains a secret.
2. Stops if any PHP or JavaScript file has a syntax error.
3. Uploads only the files that changed since the last deploy.
4. Checks the live site: the home page, stylesheet, script, catalog and Admin must load, and `config.php` must not be readable. If a check fails, the run turns red and GitHub emails you. To undo a bad change, revert the commit on `main` and the previous version deploys again.

Set up:

1. In your hosting panel create a **separate FTP account** limited to the folder that holds `index.html` and `admin.php`. Do not use your main hosting login. That keeps it away from your private data folder. Use a long random password.
2. Add the `DEPLOY_*` repository secrets from the table above.
3. Open the **Actions** tab, choose **Deploy to host**, and use **Run workflow** with *Only list what would upload* ticked. The log shows what would be sent. Run it again with the box unticked for the first real upload.
4. Optional: in **Settings → Environments → production**, add yourself as a required reviewer. Each deploy then waits for you to approve it.

Never put passwords in the repository or in chat. They belong only in the secrets list. Changes to `config.php` and `.htaccess` are made by hand on the host.

## U.S. checker (GitHub Actions)

Some stores rate-limit or block checks from certain hosting servers, for example servers outside the U.S. The U.S. runner scans from GitHub's U.S. servers instead and reports back to your site. GitHub's addresses are datacenter addresses too, so a store that blocks all datacenters may still refuse them. Use **Manual review** for those sellers.

How it works: a GitHub workflow runs `runner/run.php`. It asks `ingest.php` for the listings assigned to it, scans them with the same code the server uses (same robots.txt rules and seller pauses), and posts the readings back. Each run takes up to 3 product pages per seller and 24 listings in total, spaced at least 2 seconds apart at the same seller, then leaves that seller alone for 20 minutes. Listings that share one page (for example one listing per warehouse) are fetched once and read together, so they count as one page. The site checks every result before storing it and ignores any listing you edited while the scan ran.

Set up:

1. Create a random key of at least 32 characters. In your live `config.php`, set `'ingest_token' => 'YOUR-KEY'`.
2. Add the repository secrets `OFFGRID_TOKEN` (the same key) and `OFFGRID_URL`.
3. Make sure the site files, including `ingest.php`, are on the host.
4. In the repository **Actions** tab, run **U.S. stock checker** once with **Run workflow**. The log lists each listing claimed and its result.
5. In Admin, edit a listing and set **Checked by** to **U.S. runner**, or use **Move failing to U.S. runner** on the list to move listings that are failing or paused. The server cron then skips those listings.

### Start the runner from your site (recommended)

GitHub's own schedule can start late or skip runs. With a token, your site starts the workflow itself: whenever someone loads the catalog and U.S. runner listings are due, it asks GitHub to run the workflow, at most once every 9 minutes, and only when there is work. No cron job is needed on your host. The catalog response is sent first, so visitors never wait for it. If nobody visits the catalog, nothing starts until the next visit, and the GitHub schedule still runs as a backup.

1. On GitHub open **Settings** for your **account** (profile picture, top right), then **Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**. Developer settings does not appear in the repository's own Settings tab.
2. Set **Repository access** to **Only select repositories** and choose your tracker repository. Under **Permissions → Repository permissions** set **Actions** to **Read and write**. Leave everything else at no access. Choose an expiry date and copy the token.
3. In your live `config.php` set `'github_token' => 'THE-TOKEN'`. Check that `github_repo` is `OWNER/REPOSITORY` and that `github_workflow` is the workflow file name (`check.yml`). This token lives only in the host's `config.php`, not in GitHub secrets. It can start workflows in that one repository and nothing else.
4. In Admin, **Run U.S. checker now** starts a run immediately. If GitHub refuses a start, Admin shows a red message with the reason. A token that expires will show up there.

Admin shows a warning if a U.S. runner listing exists and the runner has not checked in for 45 minutes. GitHub can delay scheduled runs, and it turns off scheduled workflows in a public repository after 60 days without repository activity, so check the Actions tab if the warning appears. The key is sent only in an `X-Offgrid-Token` header over HTTPS. Repeated wrong keys are blocked for 15 minutes.

## Add or change a watch

Sign in to **Admin** and choose **Add listing**. Enter the radio, seller, product link, firmware, and type. Save it. The checker picks up new items on the next run. Edit a listing to change the link, log a manual price or count, queue another check, check it right now, hide it, or delete it.

Choose a **Primary use** so visitors can filter or sort listings by Companion, Tracker, Repeater, and Solar repeater. The separate **Radio type** filter covers the hardware, such as a handheld or an outdoor solar node. Assign the primary use of the linked configuration and explain alternative setups in the listing notes.

- **Automatic** tries the Shopify product feed on standard product URLs and falls back to standard product data from the store page if the feed is unavailable. Use a Shopify variant URL or enter exact option values separated by a vertical bar. For example, `MeshCore | Antenna` tracks variants with both values. A Shopify feed that allows checkout is labeled **Orderable**. A store can accept an order without having the radio on the shelf.
- **Product page** reads structured product data where available. A custom rule can check one exact product or variant block using a stock XPath and optional price XPath. Enter seller phrases that appear inside that block. For example, a status block with `id="availability"` can be selected by `//*[@id="availability"]`. Use `.` for the price only if the same block contains a single USD price. Use the rule for a specific variant if the page also sells cases, antenna bundles, or radios for another frequency.
- **Manual review** keeps the listing public while you record price and stock from the seller's page yourself. Use it when the seller blocks automated checks, does not allow them, or only shows the value after a browser interaction. Visitors see an asterisk on these listings.

Use product links that present prices in USD. Shopify feeds report the storefront's currency without identifying the currency in the product response. Structured product-page prices require an explicit USD currency, and text rules require a dollar price in the selected block. Prices exclude checkout taxes and shipping. Firmware compatibility describes the tracked listing. Use the separate setup field to say what ships installed. For sellers with several warehouses, use **Ships from** so visitors can filter by U.S., EU or China warehouse.

Checks work through the oldest eligible listings in batches. At most one scan per seller hostname starts every 20 minutes (a group of up to 3 pages), requests within a scan are spaced out, and seller crawl rules are cached for 24 hours. Paused sellers are skipped. A rate limit triggers at least an hour of backoff, or six hours when the seller gives no usable retry time. An access refusal triggers at least 24 hours of backoff. Longer seller-provided retry times take precedence. These limits can extend the time between checks for individual products.

The public page distinguishes **Awaiting first stock check**, **Seller paused automated checks**, and listings that need review under the **Unverified** stock status. The last available price is retained and labeled. Check the per-listing activity in Admin when a source needs attention.

## Alerts and change history

Each scan is compared with the previous live reading. The tracker records when a radio comes back in stock, sells out, or changes price (the last 200 changes are kept). Manual readings saved in Admin are compared the same way. Research snapshots never count as a previous reading, so the first live check or manual reading after install creates no events.

- Cards show a **Back in stock** or **Price dropped** badge for 7 days.
- `feed.php` is a public RSS feed of recent changes. The catalog footer links to it.
- Admin shows the 10 most recent changes and a visitor count for the last 14 days.
- Optional owner alerts: set `notify_email` and/or `notify_webhook` in the host's `config.php`. The webhook is an HTTPS URL that accepts a plain-text POST, such as a private ntfy.sh topic. Alerts are sent for back-in-stock, price drops, and new submissions. Many shared hosts limit or filter `mail()`, so a webhook is more reliable.

## Review public submissions

After administrator setup, visitors can choose **Submit a listing** and send a seller's direct product link. Admin shows suggestions under **Pending submissions**. Pending submissions stay private and the checker skips them. If alerts are configured, you are notified when a new one arrives, and the Admin browser tab title shows the pending count.

Choose **Review** to confirm the seller, linked variant, and firmware. Edit the details as needed, including the public notes. **Approve and publish** adds the listing to the public catalog and makes it eligible for its configured checks. **Decline** removes it from the queue. The site checks for duplicate links and keeps variant parameters when comparing product links.

## Security notes

- **Nothing private in the repository.** The repository is public. `.github/scripts/check-config.php` fails when `config.php` has any real value (`data_dir`, `setup_key`, `base_url`, `notify_*`, `ingest_token`, `github_token`) or when any tracked file looks like it holds a GitHub token, a private key, or an API secret key. It prints where the problem is, never the value. It runs on every push and pull request (`guard.yml`), before every deploy, and, once you turn it on, before each commit on your own computer (`git config core.hooksPath .githooks`). If it ever fires for a real secret, treat that secret as leaked: change it, because removing it from the repository does not remove it from history.
- **Set `base_url` in the host's `config.php`** to the public address of the folder, for example `https://YOUR-DOMAIN/offgrid/`. Owner alerts and the RSS feed then use it. If it is empty, links are built from the request's Host header, which a visitor can forge.
- **Security headers** (optional): `.htaccess.hardened` holds a stricter set of response headers (Content-Security-Policy, X-Frame-Options, Referrer-Policy, Permissions-Policy, the Cross-Origin policies and a one-year Strict-Transport-Security) so the static page and assets get them too. It is not active. It needs `mod_headers`, and on one LiteSpeed host it made every file in `assets/` return 403, so test it with the plain `.htaccess` ready to restore. HSTS applies to this host name only.
- **Cached assets:** the optional `.htaccess.hardened` also sets a 30-day cache for stylesheets, scripts and images. If you use it, change the `?v=` value where a page loads `assets/styles.css`, `assets/catalog.js` or `assets/admin.js` (`index.html`, `submit.php`, `admin.php`) whenever you change that file.
- **Rate limits** for sign-in, the runner key, submissions and the visitor counter are kept in `ratelimit.json` in the private folder, separate from `tracker.json`. It holds hashed addresses only, is capped at 2,000 entries and can be deleted at any time.
- **Visitor counter:** `visit.php` counts unique visitors per day using a salted daily hash. It sets no cookies and stores no IP addresses or user agents, and it does not count visitors who send Do Not Track.
- **`/.well-known/security.txt`** tells researchers where to report problems. Edit its `Contact` line (it points to this repository's private vulnerability reporting, which you must enable under the repository's Security settings, or replace it with an email address) and renew `Expires` each year. The share-preview tags in `index.html` and the card image `assets/og-image.png` use `https://mesh.davidandlaura.net/`; change those if the site moves.
- Open ports and software versions on the server itself, such as database, FTP and mail ports, are set by your host, not by this tracker. Ask your host to close what you don't use.

## Settings reference (`config.php`)

These live in the **host's** `config.php`. The repository's copy is a blank template.

| Key | What it does | Notes |
|---|---|---|
| `data_dir` | Full path of the private data folder | Outside every public web root. Required. |
| `setup_key` | One-time key for creating the admin password | 32+ random characters. Remove it once the password is set. |
| `base_url` | Public address of the site folder | Used in alerts and the RSS feed. Recommended. |
| `interval_minutes` | Minimum time between checks of one listing | Default 60. Seller pacing can extend it. |
| `batch_size` | Listings the server cron checks per run | Default 6, maximum 10 |
| `notify_email` | Email address for owner alerts | Optional |
| `notify_webhook` | HTTPS URL that takes a plain-text POST | Optional. An ntfy.sh topic works. |
| `ingest_token` | Key the U.S. runner uses to report checks | 32+ random characters. Must equal the `OFFGRID_TOKEN` secret. Empty turns the runner off. |
| `github_repo` | `OWNER/REPOSITORY` of this repository | For starting the runner from the site |
| `github_token` | Fine-grained token with Actions read and write on that one repository | Optional. Empty turns site-started runs off. |
| `github_workflow` | Runner workflow file name | `check.yml` |
| `github_ref` | Branch the workflow runs on | `main` |
| `local_test` | Allows loopback HTTP for local testing | Leave `false` on a public host |

## References

- cPanel Cron Jobs: https://docs.cpanel.net/cpanel/advanced/cron-jobs/
- Shopify Ajax Product API: https://shopify.dev/docs/api/ajax/reference/product
- GitHub encrypted secrets: https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions

## Versions and the What's new section

Releases are named `v<major>.<minor>` plus a codename. Each major series takes the next word from the radio alphabet (v1 is the Antenna series, v2 is Beacon, then Channel, Dipole, Echo and so on). Each minor release within a series is named for a bird (v1.0 Kestrel, v1.1 Osprey, v1.2 Harrier, v1.3 Merlin, v1.4 Condor, v1.5 Peregrine).

To publish a release, edit `index.html`: add a new `<div class="rel">` block at the top of the `#whats-new` section (and drop the oldest one into the "Earlier" line), then update the version in the `<summary>` line and in the `<p class="stamp">` line in the footer. Bump the `?v=` cache string on the CSS and JS links so browsers pick up the change.

## Adding a listing by link

In the admin, the "Add a listing by link" panel reads a store's product feed from the pasted link, lists the variants with price and stock, and opens the Add listing form already filled in. Each guess shows a short reason under the field, nothing is published until you press Save, and the first reading from the feed is saved with the listing. Referral and tracking parameters (`ref`, `utm_*` and similar) are removed from the link, and a link that is already in the catalog is refused. Stores without a feed open the form in "Product page" mode, and Etsy, DigiKey and Mouser open it as "Manual review only". The code is in `includes/addurl.php`.
