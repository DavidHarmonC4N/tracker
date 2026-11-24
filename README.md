# Offgrid Comms Tracker

A public U.S. radio catalog and a private admin page for PHP shared hosting. The catalog comes with MeshCore and Meshtastic listings from the November 24, 2026 research. Historical prices are visible immediately. Live stock appears once an automated or manual check verifies it.

## Install on shared hosting

1. In your host’s file manager, select PHP **8.3 or newer**. Enable **cURL** and **DOM** for the command-line PHP version that runs cron. Most hosts include DOM. You also need HTTPS, outbound HTTPS access, and scheduled tasks (cron).
2. Extract the archive. Upload the contents of the `offgrid-comms-tracker` folder to a dedicated web folder such as `public_html/offgrid/`. Keep the included `.htaccess` files if you use Apache. The README may stay on your computer.
3. In your hosting account’s home directory, create a writable folder named `offgrid-private` outside all public web roots. Use permissions `700` if PHP and cron run as your hosting account. Never put this folder inside `public_html`. If your host uses more than one domain, keep it outside every domain’s document root.
4. Edit the uploaded `config.php`. Enter the full folder path and a unique random setup key of at least 32 characters. For example, the folder path might be `/home/ACCOUNT/offgrid-private`. Generate the key with your password manager. If using characters such as an apostrophe or backslash, escape them in the PHP string or choose an alphanumeric random key.
5. Open `https://YOUR-DOMAIN/offgrid/admin.php`. Enter the setup key and make an administrator password. You can then remove the setup key from `config.php`. Keep the public entry point at `https://YOUR-DOMAIN/offgrid/index.html`.
6. Add a scheduled job in your hosting panel. In cPanel, open **Advanced → Cron Jobs** and set Minute to `*/10`. Set Hour, Day, Month and Weekday to `*`. Use your host’s PHP executable and your account’s actual path in the command field.

   ```sh
   /usr/local/bin/php /home/ACCOUNT/public_html/offgrid/check.php
   ```

   If your hosting provider publishes a version-specific path, use that one instead. The checker runs from the command line and needs no public cron endpoint. If the host provides a terminal, run the same command once to verify it prints a listing count. The catalog and admin show the last scheduled run. The cron schedule runs regardless of whether anyone has the site open.

No external database, package installation, or paid stock API is required. The tracker stores its settings and results in the private folder. Back up `tracker.json` and keep it private. When updating code, keep your existing `config.php` and private data. The starter catalog is used only to initialize a new installation.

## Update an existing installation

Upload the updated files over the current installation, including `submit.php`, `feed.php`, `ingest.php`, `includes/dispatch.php` and `includes/roles.json`. Keep your installed `config.php`, private folder, and existing cron job. Existing listings, stock readings, and the administrator password carry over. The update assigns primary uses to starter listings where known and leaves uncategorized listings editable in Admin.

## Security notes

- **Set `base_url` in `config.php`** to the public address of the folder, for example `https://YOUR-DOMAIN/offgrid/`. Owner alerts and the RSS feed then use it. If it is empty, links are built from the request's Host header, which a visitor can forge.
- **Security headers** (optional): `.htaccess.hardened` holds a stricter set of response headers (Content-Security-Policy, X-Frame-Options, Referrer-Policy, Permissions-Policy, the Cross-Origin policies and a one-year Strict-Transport-Security) so the static page and assets get them too. It is not active. It needs `mod_headers`, and on one LiteSpeed host it made every file in `assets/` return 403, so test it with the plain `.htaccess` ready to restore. HSTS applies to this host name only.
- **Rate limits** for sign-in, the runner key, submissions and the visitor counter are kept in `ratelimit.json` in the private folder, separate from `tracker.json`. It holds hashed addresses only, is capped at 2,000 entries and can be deleted at any time.
- **Cached assets:** the optional `.htaccess.hardened` also sets a 30-day cache for stylesheets, scripts and images. If you use it, change the `?v=` value where a page loads `assets/styles.css`, `assets/catalog.js` or `assets/admin.js` (`index.html`, `submit.php`, `admin.php`) whenever you change that file.
- **`/.well-known/security.txt`** tells researchers where to report problems. Edit its `Contact` line (it points to this repository's private vulnerability reporting, which you must enable under the repository's Security settings, or replace it with an email address) and renew `Expires` each year. The share-preview tags in `index.html` and the card image `assets/og-image.png` use `https://mesh.davidandlaura.net/`; change those if the site moves.
- Open ports and software versions on the server itself, such as database, FTP and mail ports, are set by your host, not by this tracker. Ask your host to close what you don't use.

## Add or change a watch

Sign in to **Admin** and choose **Add listing**. Enter the radio, seller, product link, firmware, and type. Save it. The checker picks up new items on the next scheduled run. Edit a listing to change the link, log a manual price or count, queue another check, hide it, or delete it.

Choose a **Primary use** so visitors can filter or sort listings by Companion, Tracker, Repeater, and Solar repeater. The separate **Radio type** filter covers the hardware, such as a handheld or an outdoor solar node. Assign the primary use of the linked configuration and explain alternative setups in the listing notes.

- **Automatic** tries the Shopify product feed on standard product URLs and falls back to standard product data from the store page if the feed is unavailable. Use a Shopify variant URL or enter exact option values separated by a vertical bar. For example, `MeshCore | Antenna` tracks variants with both values. A Shopify feed that allows checkout is labeled **Orderable**. A store can accept an order without having the radio on the shelf.
- **Product page** reads structured product data where available. A custom rule can check one exact product or variant block using a stock XPath and optional price XPath. Enter seller phrases that appear inside that block. For example, a status block with `id="availability"` can be selected by `//*[@id="availability"]`. Use `.` for the price only if the same block contains a single USD price. Use the rule for a specific variant if the page also sells cases, antenna bundles, or radios for another frequency.
- **Manual review** keeps the listing public while you record price and stock from the seller’s page yourself. Use it when the seller blocks automated checks, does not allow them, or only shows the value after a browser interaction.

Use U.S. product links that present prices in USD. Shopify feeds report the storefront’s currency without identifying the currency in the product response. Structured product-page prices require an explicit USD currency, and text rules require a dollar price in the selected block. Prices exclude checkout taxes and shipping. Firmware compatibility describes the tracked listing. Use the separate setup field to say what ships installed.

The job works through the oldest eligible checks in batches. It starts at most one listing scan per seller hostname every 20 minutes, spaces requests within a scan, and caches seller crawl rules for 24 hours. It skips paused sellers without counting them toward the batch. A rate limit triggers at least an hour of backoff, or six hours when the seller gives no usable retry time. An access refusal triggers at least 24 hours of backoff. Longer seller-provided retry times take precedence. These limits can extend the time between checks for individual products.

The public page distinguishes **Awaiting first stock check**, **Seller paused automated checks**, and listings that need review under the **Unverified** stock status. The last available price is retained and labeled. Check the per-listing activity in Admin when a source needs attention.

## U.S. checker (GitHub Actions)

Some stores rate-limit or block checks from certain hosting servers, for example servers outside the U.S. The U.S. runner scans from GitHub's U.S. servers instead and reports back to your site. GitHub's addresses are datacenter addresses too, so a store that blocks all datacenters may still refuse them. Use **Manual review** for those sellers.

How it works: every 10 minutes a GitHub workflow runs `runner/run.php`. It asks `ingest.php` for the listings assigned to it, scans them with the same code the server uses (same robots.txt rules and seller pauses), and posts the readings back. Each run takes up to 3 product pages per seller and 24 listings in total, spaced at least 2 seconds apart at the same seller, then leaves that seller alone for 20 minutes. Listings that share one page (for example one listing per warehouse) are fetched once and read together, so they count as one page. The site checks every result before storing it and ignores any listing you edited while the scan ran.

Set up:

1. Put this folder in a GitHub repository, including the `.github` and `runner` folders. A **public** repository has unlimited free Actions minutes. For a private one, change the schedule in `.github/workflows/check.yml` to `*/30` to stay inside the free 2,000 minutes. Nothing secret is in the repository: your real `config.php` and data folder stay on your host, so never commit them.
2. Create a random key of at least 32 characters. In your live `config.php`, set `'ingest_token' => 'YOUR-KEY'`.
3. In the GitHub repository open **Settings → Secrets and variables → Actions** and add two repository secrets: `OFFGRID_TOKEN` (the same key) and `OFFGRID_URL` (the folder that contains `ingest.php`, for example `https://YOUR-DOMAIN/offgrid/`).
4. Upload the updated site files, including `ingest.php`. The `.github` and `runner` folders do not need to be on the host.
5. In the repository **Actions** tab, enable workflows and use **Run workflow** once. The log lists each listing claimed and its result.
6. In Admin, edit a listing and set **Checked by** to **U.S. runner**, or use **Move failing to U.S. runner** on the list to move listings that are failing or paused. The server cron then skips those listings.

### Start the runner from your site (recommended)

GitHub's own schedule can start late or skip runs. With a token, your site starts the workflow itself: whenever someone loads the catalog and U.S. runner listings are due, it asks GitHub to run the workflow, at most once every 9 minutes, and only when there is work. No cron job is needed on your host. The catalog response is sent first, so visitors never wait for it. If nobody visits the catalog, nothing starts until the next visit, and the GitHub schedule still runs as a backup.

1. On GitHub open **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. Set **Repository access** to **Only select repositories** and choose your tracker repository. Under **Permissions → Repository permissions** set **Actions** to **Read and write**. Leave everything else at no access. Choose an expiry date and copy the token.
3. In your live `config.php` set `'github_token' => 'THE-TOKEN'`. Check that `github_repo` is `OWNER/REPOSITORY` and that `github_workflow` is the workflow file name (`check.yml`). Keep this token private: it can start workflows in that one repository and nothing else.
4. In Admin, **Run U.S. checker now** starts a run immediately. If GitHub refuses a start, Admin shows a red message with the reason. A token that expires will show up there.

Admin shows a warning if a U.S. runner listing exists and the runner has not checked in for 45 minutes. GitHub can delay scheduled runs, and it turns off scheduled workflows in a public repository after 60 days without repository activity, so check the Actions tab if the warning appears. The key is sent only in an `X-Offgrid-Token` header over HTTPS. Repeated wrong keys are blocked for 15 minutes.

## Alerts and change history

Each scan is compared with the previous live reading. The tracker records when a radio comes back in stock, sells out, or changes price (the last 200 changes are kept). Manual readings saved in Admin are compared the same way. Research snapshots never count as a previous reading, so the first live check or manual reading after install creates no events.

- Cards show a **Back in stock** or **Price dropped** badge for 7 days.
- `feed.php` is a public RSS feed of recent changes. The catalog footer links to it.
- Admin shows the 10 most recent changes.
- Optional owner alerts: set `notify_email` and/or `notify_webhook` in `config.php`. The webhook is an HTTPS URL that accepts a plain-text POST, such as a private ntfy.sh topic. Alerts are sent for back-in-stock, price drops, and new submissions. Both settings are optional, so existing `config.php` files keep working; add the two lines to turn alerts on. Many shared hosts limit or filter `mail()`, so a webhook is more reliable.

## Review public submissions

After administrator setup, visitors can choose **Submit a listing** and send a U.S. seller’s direct product link. Admin shows suggestions under **Pending submissions**. Pending submissions stay private and the checker skips them. If alerts are configured, you are notified when a new one arrives, and the Admin browser tab title shows the pending count.

Choose **Review** to confirm the seller, linked variant, and firmware. Edit the details as needed, including the public notes. **Approve and publish** adds the listing to the public catalog and makes it eligible for its configured checks. **Decline** removes it from the queue. The site checks for duplicate links and keeps variant parameters when comparing product links.

References for hosting and store feeds:

- cPanel Cron Jobs: https://docs.cpanel.net/cpanel/advanced/cron-jobs/
- Shopify Ajax Product API: https://shopify.dev/docs/api/ajax/reference/product
