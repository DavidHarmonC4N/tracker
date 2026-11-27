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
5. In Admin, edit a listing and set **Checked by** to **U.S. runner**, or use **Move failing to U.S. runner** on the list to move listings that are failing or paused. If you have no server cron, use **Move all N to U.S. runner**: a listing still set to "This server" is never checked without a cron, so this moves every automatic listing to the runner in one step. The server cron then skips those listings.

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
- Optional owner alerts: set `notify_email` and/or `notify_webhook` in the host's `config.php`. Alerts are sent for back-in-stock, price drops, and new submissions. Several changes found in one run arrive as **one message**, not one each. In the admin, the **Alerts** panel shows what is set up (the email is masked), a **Send test alert** button that reports exactly what happened, and the latest results, including failures.
  - **Email** is sent from `alerts@` your site's own domain, or from `notify_from` if you set it. Use an address on a domain your host sends mail for, because mail that claims to come from elsewhere is often filtered as spam. "Handed to the host's mail system" does not prove delivery, so check the inbox and the spam folder after a test. If the domain has more than two labels in its registrable part (such as `example.co.uk`), set `notify_from` yourself.
  - **Webhook** is an HTTPS URL that accepts a plain-text POST, such as a private ntfy.sh topic. The test shows whether it answered with success. Many shared hosts limit or filter `mail()`, so a webhook is more reliable.
  - A staging copy never sends alerts.

### Phone alerts with ntfy (two topics)

[ntfy](https://ntfy.sh) sends a phone notification for every message posted to a topic. Install the ntfy app, subscribe to your topic, and you get alerts. Topics are open to anyone who knows the name, so use long random names. ntfy itself is one-way (the site posts, your phone receives), but the notifications carry tap buttons that call back to the site, which answers in the same topic.

1. **Your private topic.** Subscribe to a topic with a long secret name, for example `https://ntfy.sh/offgrid-` plus 20 random characters, and put that address in `notify_webhook`. You get every alert, with an emoji tag, a higher priority for back in stock, and tap to open the listing. Press **Send test alert** in the admin to check it.
2. **Tap buttons (optional).** Set `ntfy_token` to a secret of 32 or more random characters, and set `base_url`. Alerts then carry **Open listing**, **Status** (a summary of stock, the runner and the latest changes) and **Mute 24h**. The reply to a button appears in your topic with **Check now** (starts the U.S. runner), **Drops this week** and **Mute 24h** or **Unmute**. The buttons call `ntfy.php` with the token in a request header. Muting silences your private alerts for a day and does not affect public topics. After ten wrong tokens from one address the page stops answering for 15 minutes. Anyone who can read your private topic can see the token, which is one more reason to keep its name secret. Change `ntfy_token` if you think it leaked.
3. **A public topic (optional, off by default).** Set `notify_public_topic` to a topic address of your choice, for example `https://ntfy.sh/offgrid-comms-tracker`. It carries only public news: back in stock and price drops. Each radio also gets its own topic (the public name, a dash and eight characters), so a visitor can follow only the radios they care about, with no account. The site then shows **Phone alerts (ntfy)** in the footer, and **Phone alerts (all)** and **Phone alerts for this radio** in My picks. The admin Alerts panel has **Send test post to the public topic**.
   - **Know the risk:** anyone who knows a topic name can post to it, so someone could send followers a fake alert with a link to anywhere. The site only posts public facts, and the notification's tap opens the seller's page, but followers cannot tell a fake from a real post. If that ever happens, change the topic name, or use a reserved topic (an ntfy account feature) or your own ntfy server, where only you can post.

The **Phone alerts** page (`alerts.html`) explains all of this to visitors, with store links for the ntfy app. It reads the public topic from the site, so the topic name is never written into the repository. The footer and My picks link to it once `notify_public_topic` is set.

Typed commands are not supported: your host has no always-running process to listen to a topic, so the buttons do the two-way part.

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
| `notify_from` | Address alert emails come from | Optional. Default is `alerts@` your domain. |
| `notify_public_topic` | A public ntfy topic address for visitors | Optional, off by default. See "Phone alerts with ntfy". |
| `ntfy_token` | Secret for the tap buttons (32 or more characters) | Optional. Turns on the buttons. |
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

To publish a release, edit `index.html`, `prices.html` and `alerts.html` (all three carry the same footer; `radios.php` reads the footer from `index.html`): add a new `<div class="rel">` block at the top of the `#whats-new` section (and drop the oldest one into the "Earlier" line), then update the version in the `<summary>` line and in the `<p class="stamp">` line in the footer. Bump the `?v=` cache string on the CSS and JS links so browsers pick up the change.

## Adding a listing by link

In the admin, the "Add a listing by link" panel reads a store's product feed from the pasted link, lists the variants with price and stock, and opens the Add listing form already filled in. Each guess shows a short reason under the field, nothing is published until you press Save, and the first reading from the feed is saved with the listing. Referral and tracking parameters (`ref`, `utm_*` and similar) are removed from the link, and a link that is already in the catalog is refused. Stores without a feed open the form in "Product page" mode, and Etsy, DigiKey and Mouser open it as "Manual review only". The code is in `includes/addurl.php`.

## Price history

Each time a check finds a new price or stock status, the tracker adds a point to that listing's history (kept in `tracker.json`, up to 120 points per listing, only on change). `history.php` serves it to the Price history page, and `catalog.php` adds the lowest and highest recorded price to each listing for the "Lowest seen" badge and filter. History starts the day this ships, so older price moves are not available.

## Tests

The tests live in `tests/` and never touch your real `config.php`, data folder or host: `tests/run.sh` copies the site to a temporary folder, starts PHP's built-in server on two local ports, runs everything, and stops the servers.

```bash
cd tests && npm ci && npx playwright install chromium   # once, for the browser tests
bash tests/run.sh                  # everything
bash tests/run.sh php              # only the PHP tests (needs PHP 8.3, no Node)
bash tests/run.sh browser prices   # only browser tests whose file name contains "prices"
```

- `tests/php/*.test.php` check the rules directly: link validation, matching a store variant, change events, price history, the Add by link guessing, the starter listings, and that the public catalog exposes no private fields.
- `tests/browser/*.test.js` drive a real Chromium: filters and badges, My picks, roll-up sections, the Price history page, the What's new stamp, layout checks (title wrapping, phone widths, links that open in a new tab), and the admin Add by link flow. The admin test replaces the store fetch with `tests/fixtures/rokland-wismesh.json`, so no test contacts a real shop.
- Each file prints `PASS` or `FAIL` lines and ends with `ALL PASSED` or a failure count; `run.sh` exits non-zero if any file fails.
- GitHub runs the same command on every push and pull request (`.github/workflows/tests.yml`). The deploy workflow does not wait for it, and `tests/` is never uploaded to the host.
- To add a test, copy a file in `tests/php/` or `tests/browser/`; the browser helper in `tests/browser/lib.js` sets up the page, the checks and the JavaScript-error watch.

## Staging site (a private test copy on the host)

A staging copy lets you try a branch on the real server before it goes live. It is a second copy of the site in its own folder, with its own settings and its own data, behind a password. Visitors and search engines cannot reach it, and it can never send you alerts or start the real U.S. runner.

**One-time setup on the host** (these steps need your host's control panel; the repository cannot do them for you):

1. **Make a folder for it.** The simplest choice is a subfolder of the live site, such as `public_html/staging/` (so the address is `https://your-site/staging/`). A subdomain with its own folder also works.
2. **Password protect that folder first.** In cPanel this is *Directory Privacy*: choose the folder, tick "Password protect this directory", and add a user and password. Without cPanel, put an `AuthType Basic` rule and a `.htpasswd` file in the folder. The staging workflow will refuse to upload anything until the folder answers HTTP 401 without a password.
3. **Give it its own data folder**, outside every web folder, such as `/home/you/offgrid-staging-data/`. Never reuse the live data folder.
4. **Create `config.php` inside the staging folder** by copying the template, then set: `data_dir` to the staging data folder, a new `setup_key` (32 or more random characters), `base_url` to the staging address, and **`'staging' => true`**. Leave `notify_email`, `notify_webhook`, `ingest_token`, `github_repo` and `github_token` empty. The staging copy ignores them anyway.
5. **Add these GitHub repository secrets** (Settings, Secrets and variables, Actions). The upload reuses your existing `DEPLOY_HOST`, `DEPLOY_USER` and `DEPLOY_PASSWORD`.

| Secret | What to put |
|---|---|
| `STAGING_DEPLOY_DIR` | The FTP path of the staging folder, such as `/public_html/staging/`. It must not be the same as `DEPLOY_DIR`. |
| `STAGING_URL` | The staging address, such as `https://your-site/staging/`. |
| `STAGING_USER`, `STAGING_PASSWORD` | Optional. The Directory Privacy user and password, so the workflow can also check that staging itself works. |

**Using it:** open the **Actions** tab, choose **Deploy to staging**, press **Run workflow**, and pick the branch to try. Pushing to a branch named `staging` deploys it automatically. Then open the staging address, enter the password, and visit `admin.php` to set the staging admin password the first time.

**What is different about a staging copy:**

- Every page shows a **STAGING** bar and the tab title starts with `[STAGING]`.
- It sends `X-Robots-Tag: noindex` and the deploy adds a `robots.txt` that blocks crawlers.
- It starts from the starter listings with its own empty history. It never receives live data, and nothing it does reaches the live site.
- Alerts, the GitHub runner trigger and runner uploads are switched off in code when `staging` is true, so copied-in live settings cannot cause side effects.
- The staging workflow refuses to run if the staging folder is the same as, or contains, the live folder, or if the staging address equals the live address. It never uploads `config.php`, `.htaccess` or `.htpasswd`, and after every upload it checks again that the site answers 401 to the public.
- Use **Check now** in the staging admin to fetch real prices from the host; staging checks run from the host's own server (there is no U.S. runner there).

## Search engines and AI tools (SEO)

- **`radios.php`** is a plain, server-rendered list of every radio with its price, stock, seller, warehouse and last check. The home page's catalog is built by JavaScript, which most crawlers and AI tools do not run, so this page puts the same facts in the HTML. It reuses the home page's header and footer, so it cannot drift from them.
- **`robots.txt`** allows crawlers and blocks only the private endpoints. It deliberately does **not** block `catalog.php` or `history.php`, because search engines need them to render the catalog and price history. Those two send `X-Robots-Tag: noindex` so they are not indexed as pages. Its `Sitemap:` line names the production address: change it if the domain changes. The staging deploy replaces it with a block-everything file.
- **`sitemap.php`** lists the four public pages with a `lastmod` taken from the live data, and is named in `robots.txt`. Submit it in Google Search Console.
- **`llms.txt`** is an optional plain summary for AI tools (Google ignores it). **`favicon.ico`** and **`assets/apple-touch-icon.png`** are generated from `assets/favicon.svg`.
- The home page has `WebSite` and `Organization` structured data (JSON-LD) and a descriptive title and description. `radios.php` has `CollectionPage` data.
- **Search Console:** add the property `https://mesh.davidandlaura.net/`, verify it with an HTML tag or file (add the tag to the `<head>` of `index.html`, or put the file in the repository root), then submit `sitemap.php` and use URL inspection on the four pages.
- Not done here because they need the host's `.htaccess`, which the deploy never touches: a 301 from `index.html` to `/`, and HSTS with the other security headers on static pages.

### Per-radio pages

`radio.php?r=<name>-<code>` is a page for one radio: what it is, price and stock now (one row per warehouse), the recorded price history (a chart drawn on the server, plus a table of changes), and similar radios. It is plain HTML and needs no JavaScript.

- **One page per product link.** Listings that share a product page (Seeed lists the same page once per warehouse) are one radio, so there are no near-duplicate pages. `includes/radio.php` does the grouping.
- **Stable addresses.** The last eight characters are a code made from the product link; the name part is only for readers. If a listing is renamed, the old address answers with a 301 to the new one. An unknown or malformed address is a 404 that is not indexed.
- **Where they are linked:** from each catalog card ("Details and price history"), from the plain list at `radios.php`, from the breadcrumb, and from the sitemap, which lists every radio page with its latest check time.
- **No Product or Offer markup on purpose.** The offers belong to other sellers, and Google's merchant rules expect that markup only where the item is sold. The pages carry `WebPage` and `BreadcrumbList` data.

### Notify me block

When `notify_public_topic` is set, every radio page shows a "Get notified about this radio" block: the radio's ntfy topic with a Copy button, an Open in ntfy app link, an RSS link limited to that radio, and a short how-to. It is plain HTML and works without JavaScript (only Copy needs `assets/notify.js`). With no public topic the block is not on the page.

There is one topic per radio page, not per listing. The lead listing (U.S. warehouse first) names it (`includes/radio.php`, `radio_lead_ids()`), so a radio sold from several warehouses has one topic, and a single-listing radio keeps the topic it already had.

### Antennas

The catalog has two categories, Radios and Antennas, told apart by the listing's type (`antenna_omni`, `antenna_directional`, `antenna_whip`, `antenna_cable`; see `KINDS` in `includes/app.php`). An antenna listing carries `specs` (band, gain, connector, length), which the admin form edits under "Antenna facts". The public catalog adds `category` and `specs` to each item.

- **Catalog:** the category switch sits above the availability tabs and is saved in the link (`?cat=antennas`). Band and connector choices are built from the antennas that exist. Radio-only filters are hidden for antennas, and the other way round.
- **Pages:** each antenna has a page like a radio's, with an "About this antenna" list. `radios.php` lists antennas in their own section (`#antennas`).
- **Starter list:** 22 Rokland antennas, all read automatically from the store's product feed. They are `late` listings, so an existing install gets each one once. Sellers that block automated checks are not used.
- **Manual-entry listings:** the starter list has none, and the admin has a "Remove N manual-entry" button that deletes the ones an install already has.

### Reading a whole store at once

A Shopify store publishes all its products at `/products.json`. When the U.S. runner is handed three or more listings from one store, it reads that feed (100 products a page, up to 8 pages, stopping as soon as every wanted listing is found) instead of one request per listing. `shopify_bulk_lookup()` in `includes/scanner.php` does the reading, and `shopify_feed_to_product()` converts a feed product into the shape of the per-page `.js` feed, so both go through `shopify_result()` and give the same answer (`tests/php/bulk.test.php` checks this against real Rokland data in `tests/fixtures`).

- **The server learns which stores work.** The runner reports the stores it read in bulk (`bulk_hosts`) and the ones whose feed failed (`bulk_failed`). A store flagged as readable is claimed whole (up to 60 pages) on the next claim, other stores keep the limit of three pages a run. A flag the runner has not confirmed for 14 days expires.
- **If a feed stops working,** the runner falls back to one page per listing for that store, for at most three pages a run, and reports the failure so the flag is dropped.
- **Limits:** the runner claims up to 60 listings a run (it was 24), and the server accepts up to 60 results at a time.

### Antennas that fit

Each radio can carry `antenna_port` (`sma`, `rp-sma`, `n`, `ufl` or `none`, see `ANTENNA_PORTS`). It is blank unless a seller or the maker states it. Starter values are in `includes/ports.json`, filled in by `with_roles()` for radios saved before the field existed, and the admin form can set it per listing.

- **Radio pages** list antennas whose connector matches the port (`radio_fitting_antennas()`), only 915 MHz or dual-band ones, in stock first and then cheapest. A U.FL port is pointed at U.FL to SMA pigtail cables. A radio with no external port says its antenna is built in.
- **Antenna pages** list radios whose port matches (`antenna_fitting_radios()`). Cables and NMO antennas list none.
- **Connector words:** `antenna_connector_key()` reads the seller's wording ("SMA male", "N-Type", "U.FL to RP-SMA female"). RP-SMA is never read as SMA. Nothing is guessed when the connector is unknown.
- **Sellers:** the starter list also has antennas from Atlavox, RAKwireless, ameriDroid, SpecFive and Muzi Works. Their connectors, bands and lengths were taken from the product pages. Every one is read automatically from the store's product feed. MeshSmith (`shop.meshsmith.net`, a Shopify store behind the `meshsmith.net` site) adds three 1 W boards (Photon-1W, PiMesh-1W, EtherMesh-1W, tracked by their 915 MHz options) and three antennas or pigtails.

### Checker health alerts

If the checks stop, prices and stock quietly go out of date, so the owner is told (`includes/health.php`):

- **Stalled checker:** no runner check-in for 3 hours (or no server cron run, for a site checked by its own cron). The message says how long and what to check: the scheduled workflow in GitHub Actions, the `OFFGRID_TOKEN` secret against `ingest_token`, and the latest run's log.
- **Seller trouble:** every listing from a seller (at least two) has needed review for 3 hours. The message names the seller and the first error.
- **Once only, then recovery:** each problem is reported once, and "Checker is back" or "Seller recovered" is sent when it clears. A seller failure that clears within 3 hours says nothing.
- **How it runs:** on catalog requests, at most every five minutes (`health.check` in the data folder), so it needs no cron. It uses the owner alert channels. The staging guard applies, but muting does not: a mute is for stock noise, and these messages say the stock data itself is going stale, so they are sent even while muted (as their own message, separate from stock alerts). The Alerts panel in Admin shows the current state.

### Group directory

`groups.php` lists 234 local Meshtastic and MeshCore groups across the U.S., from `includes/groups.json`. It is server-rendered, so it works without JavaScript and search engines can read it.

- **Pages:** `groups.php` (a tile map of the states, a find form, and a list of every state), and `groups.php?state=<slug>` for each of the 53 states and regions. Filters are a plain GET form (`q`, `protocol`, `discord=1`), and a filtered or unknown-state page is `noindex`. The sitemap lists the directory and every state page.
- **Caution on every page:** the list was found by an automated search and nobody here has checked the groups or their pages. The site is not responsible for what is on them and does not endorse any group (`GROUPS_DISCLAIMER` in `includes/groups.php`, shown at the top and the bottom).
- **Outside links:** every link opens in a new tab with `rel="noopener noreferrer nofollow"`, is https only, and is cleaned on load (`groups_url()`), so a bad row can never put a broken or unsafe link on the page. A Discord link that needs existing membership is shown as a greyed "members only" label, not a join button. A site the research could not open is marked "Website not confirmed".
- **Map:** a tile map drawn on the server (`groups_map_svg()`). Every state is one tile of the same size, so small states stay clickable, and tiles are shaded by how many groups they have.
- **Data:** regenerate `includes/groups.json` from the research spreadsheet with `python3 tests/tools/import-groups.py <sheet.xlsx>` (needs openpyxl), then run the tests. A group that serves several states is one entry listing each state.
- **Admin:** the Groups tab in `admin.php` (`?view=groups`, template `includes/admin-groups.php`) lists every group with search and filters (protocol, shown, hidden, edited, added here, website not confirmed). Add a group, edit one, hide or show it, restore a group from the file to its original, or delete one added here. Changes are kept in `tracker.json` under `groups` on top of `includes/groups.json`, so the file is never changed and a refreshed file keeps your edits (`groups_apply()`). Saves are checked: https links only, a real protocol and state, and a main link that is already listed is refused.
- **Still to come:** a way for visitors to send corrections.

### Starting order

The catalog opens sorted by price, low to high, so the cheapest listing that is available now comes first (listings with no price come last). It is one flat list. "Availability" is still in the sort box and groups the list by status, and a link keeps any order other than the starting one (`?sort=availability`). Clear all goes back to price, low to high.

### One card per product

Listings of the same product are shown as one card (`groupRows()` and `makeGroup()` in `assets/catalog.js`). Listings are grouped by their product page (`row.page`), which already puts Seeed's U.S., EU and China listings together. Filters, the price range and the quick filters apply to each listing first, then what is left is grouped, so a card shows only the options that matched.

- **The card** takes its status and price from the best option (best stock status, then lowest price), says "From" when options differ or "Same price in all N", and has a folded "Compare N options" table with each option's price, stock and its own link (https, new tab). The main button opens the cheapest option.
- **Tabs, counts and sorting** work on products. "Lowest seen", "Limited stock" and "Dropped" badges show when any option qualifies.
- **My picks:** starring a grouped card watches every option, and My picks still lists each option as its own card, since picks, links and the RSS feed are per listing.
- **The Seeed quick filter** keeps its warehouse sections, so it shows listings ungrouped.
- **Not grouped:** colors and sizes that are separate listings with separate links (a different product page per variant) stay separate cards.

### The plain list

`radios.php` follows the catalog: one row per product (warehouses of the same product page share a row, `radios_units()`), each table sorted cheapest first (no price last, then by name), in the same availability sections as before. Five columns that fit without sideways scrolling on a desktop: the name (with seller and firmware or antenna facts under it), price, stock (and the time of the last check), where each option ships from with its price, and a link for each warehouse. The antennas section uses the same rows and order, showing band, gain, connector and length under the name.

### Group link check

Once a month (the 1st, 07:17 UTC, `.github/workflows/links.yml`, or "Check links now" in Admin when `github_token` is set) `runner/links.php` asks the site for every link of every shown group (`ingest.php?action=group_jobs`), visits each one, and posts what it found (`group_results`). It runs on GitHub because that can reach every site. The rules are in `includes/linkcheck.php`.

- **Results:** `ok` (it answered, redirects followed), `blocked` (401, 403 or 429: it refused a robot, so we cannot tell), `unchecked` (Facebook, which answers the same for every address, and members-only Discord channels), `dead` (404, 410, no DNS, no connection, timeout) or `error` (a 5xx). Discord invites are looked up through Discord's public invite lookup. A failure is retried twice, four seconds apart, before it counts. Each site is visited at most once a second and each address once, however many groups share it.
- **Two in a row:** one failed check is kept and shown in Admin (a "Link problem" tag and filter, and a result table on the group's edit page). Two in a row put a note on the public card ("Did not respond when last checked") and make that link look faded, and the owner gets one alert naming the group and link. The link itself stays, since a site can be down for a month and come back. An answer, a refusal or "cannot check" clears the count.
- **Stale results:** results are tied to the address that was checked (a short hash), so editing an address in Admin drops its old result, and a result that arrives for an address changed meanwhile is ignored.
- **The public "List compiled" date** does not change when links are checked.
