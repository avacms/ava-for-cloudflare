# Ava for Cloudflare®

Automatically purge your Cloudflare cache when content is rebuilt, ensuring visitors always see the latest version of your site.

## Features

- **Automatic cache purge** — Clears Cloudflare cache whenever the content index is rebuilt
- **CLI commands** — Manual purge and status check from the command line
- **Logging** — All purge operations are logged to `storage/logs/cloudflare.log`

## Quick Start

1. [Create a Cloudflare API Token](#creating-an-api-token)
2. Add configuration to `app/config/ava.php`:

```php
'cloudflare' => [
    'enabled'   => true,
    'zone_id'   => 'your-zone-id-here',
    'api_token' => 'your-api-token-here',
],
```

3. Test your setup:

```bash
./ava cloudflare:status
./ava cloudflare:purge
```

---

## Creating an API Token

Cloudflare API Tokens provide scoped access to specific resources. For this plugin, you need a token with **Cache Purge** permission.

### Step-by-Step

1. Log in to the [Cloudflare Dashboard](https://dash.cloudflare.com)

2. Click your profile icon (top right) → **My Profile**

3. Select **API Tokens** from the left sidebar

4. Click **Create Token**

5. Find **"Edit zone cache"** template and click **Use template**

   This template includes the `Cache Purge` permission needed by this plugin.

6. Under **Zone Resources**, select:
   - **Include** → **Specific zone** → *Your domain*

7. (Optional) Add a **Client IP Address Filtering** for extra security:
   - Add your server's IP address to restrict where the token can be used

8. Click **Continue to summary** → **Create Token**

9. **Copy the token immediately** — it won't be shown again!

### Token Permissions (if creating custom)

If you prefer to create a custom token instead of using the template:

| Permission | Access |
|------------|--------|
| Zone → Cache Purge | Edit |

---

## Finding Your Zone ID

1. Log in to the [Cloudflare Dashboard](https://dash.cloudflare.com)
2. Select your domain
3. On the **Overview** page, scroll down the right sidebar
4. Find **Zone ID** and copy it

---

## CLI Commands

### Check Status

```bash
./ava cloudflare:status
```

Shows whether the integration is configured and active.

### Purge Cache

```bash
./ava cloudflare:purge
```

Manually purge all cached content from Cloudflare. This is the same operation that runs automatically on rebuild.

---

## Automatic Purging

When enabled, the plugin hooks into `indexer.rebuild` and purges the cache whenever:

- You run `./ava rebuild`
- Auto-rebuild triggers (when `content_index.mode` is `auto`)

---

## Recommended Cloudflare Settings

For maximum performance with Ava, configure Cloudflare to cache your entire site. Since Ava purges the cache on every rebuild, you don't need to worry about stale content.

### Cache Rules (Recommended)

In Cloudflare Dashboard → **Caching** → **Cache Rules**, create a rule:

| Setting | Value |
|---------|-------|
| **Rule name** | Cache Everything |
| **When** | Hostname equals `yourdomain.com` |
| **Then** | Eligible for cache, Cache TTL: 1 year |
| **Edge TTL** | 1 year (or maximum allowed) |
| **Browser TTL** | Respect Existing Headers (or 1 day) |

### Page Rules (Legacy Alternative)

If you prefer Page Rules (legacy feature):

1. Go to **Rules** → **Page Rules**
2. Create a rule for `yourdomain.com/*`
3. Set **Cache Level** to **Cache Everything**
4. Set **Edge Cache TTL** to the maximum (1 month or 1 year)
5. Set **Browser Cache TTL** to a reasonable value (1 day recommended)

### Why "Cache Everything"?

By default, Cloudflare only caches static assets (images, CSS, JS). The "Cache Everything" setting tells Cloudflare to also cache HTML pages. Combined with this plugin's automatic purge on rebuild, you get:

- ⚡ **Faster load times** — HTML served from Cloudflare's edge
- 🌍 **Global CDN** — Content served from the nearest data center
- 💰 **Reduced origin load** — Fewer requests hit your server
- 🔄 **Always fresh** — Cache purged automatically when you publish

### Excluding Dynamic Pages

If you have pages that shouldn't be cached (like `/api/*`), add a Cache Rule with higher priority:

| Setting | Value |
|---------|-------|
| **When** | URI Path starts with `/api` |
| **Then** | Bypass cache |

---

## Troubleshooting

### "API error: Invalid API token"

- Verify the token was copied correctly (no extra spaces)
- Check the token hasn't expired
- Ensure the token has Cache Purge permission for your zone

### "API error: Zone not found"

- Verify the Zone ID is correct
- Ensure the API token has access to this specific zone

### Purge succeeds but old content still appears

- Check browser cache (try hard refresh: Ctrl+Shift+R / Cmd+Shift+R)
- Verify Cloudflare is actually proxying your domain (orange cloud icon in DNS)
- Check if you have Browser Cache TTL set too high

### Check the logs

```bash
./ava logs:tail cloudflare
```

---

## Security Best Practices

1. **Use scoped tokens** — Only grant Cache Purge permission, nothing more
2. **Restrict by zone** — Token should only access your specific domain
3. **IP filtering** — If possible, restrict token to your server's IP
4. **Rotate regularly** — Create new tokens periodically and revoke old ones
5. **Never commit tokens** — Keep `ava.php` out of version control or use environment variables:

```php
'cloudflare' => [
    'enabled'   => true,
    'zone_id'   => getenv('CLOUDFLARE_ZONE_ID'),
    'api_token' => getenv('CLOUDFLARE_API_TOKEN'),
],
```

---

## License

This plugin is part of Ava CMS and is released under the same GPLv3 license.

Cloudflare® is a registered trademark of Cloudflare, Inc. This plugin is not affiliated with or endorsed by Cloudflare, Inc.

Provided as free, open-source software without warranty (GNU General Public License). It is under active development and may contain bugs or security issues. You are responsible for reviewing, testing, and securing any deployment.

Copyright (c) 2025-2026 Adam Greenough

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see https://www.gnu.org/licenses/.
