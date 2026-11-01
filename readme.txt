=== DEV3 AI Review Summarizer & Sentiment Analyzer for WooCommerce ===
Contributors: dev3tech
Tags: woocommerce, reviews, ai, review summary, sentiment analysis
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 7.0
WC tested up to: 11.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn dozens of product reviews into one honest AI summary: what buyers love, things to consider, sentiment, quality, value and fit scores.

== Description ==

Shoppers don't read 40 reviews. DEV3 AI Review Summarizer reads them for them, and shows a clean, accessible summary on every product page.

**What the summary shows**

* **What buyers love / Things to consider**: short, concrete pros and cons that come up again and again.
* **One-paragraph summary**: a neutral overview written for shoppers.
* **Sentiment bar**: share of positive, neutral and negative reviews.
* **Scores**: quality and value for money (1–5), plus a fit scale (runs small, true to size, runs large) for clothing and shoes. Scores that reviewers don't discuss are hidden.
* **Keywords & themes**: recurring topics tagged positive, neutral, negative or mixed.

**Built for store owners**

* **Automatic updates**: new, approved, edited or removed reviews queue a background update (Action Scheduler, bundled with WooCommerce, with a WP-Cron fallback). Reviews arriving close together are summarised in one request.
* **No wasted API calls**: summaries are cached in product meta together with a fingerprint of the review set. Nothing is sent again until the reviews change.
* **Nightly refresh** of stale and missing summaries, and **bulk "Generate missing" / "Refresh stale"** with a live progress bar.
* **Moderation**: view, edit and **lock** any summary. Locked summaries are never overwritten automatically.
* **Prompt-injection safe**: reviews are treated as untrusted data. A review that tries to instruct the AI ("ignore all previous instructions…") is ignored and flagged for moderators, and links are stripped from the output.
* **Minimum review threshold**: products with too few reviews show nothing.
* **Summary language**: your store language, or the language most reviews are written in.

**Display anywhere**

* Auto-insert **above the reviews list**, **below the Add to cart button**, in its own **"Review summary" tab**, or turn it off.
* `[dev3rs_summary id="123"]` shortcode and an **AI Review Summary** block (dynamic, works in the Site Editor's single product template).
* Card or compact layout, your colours, corner radius, custom headings, and toggles for each section, with a live preview.
* Works with classic and block themes. Server-rendered, accessible markup, about 6 KB of CSS, no jQuery and no JavaScript on the storefront.
* Renders nothing when a product has no summary. It never prints fake schema.org ratings.

Compatible with WooCommerce High-Performance Order Storage (HPOS) and the block-based Cart & Checkout.

== Installation ==

1. Install and activate WooCommerce.
2. Upload `dev3-ai-review-summarizer-for-woocommerce.zip` in Plugins → Add New → Upload Plugin, then activate it.
3. Create an API key at https://aistudio.google.com/app/apikey.
4. Go to WooCommerce → DEV3 AI Review Summaries → API Config, paste the key and click "Validate & save".
5. On the Products tab, click "Generate missing" to summarise every product that has enough reviews.

You can also define the key in wp-config.php: `define( 'DEV3RS_GEMINI_API_KEY', 'your-key' );`

== Frequently Asked Questions ==

= Which AI does it use? =
The summaries are powered by the Google Gemini API, using your own API key. The default model is gemini-2.5-flash; you can pick any model your key can use under API Config.

= How much does it cost to run? =
One request per product each time its reviews change, typically 1,500–2,500 tokens. Google AI Studio keys include a free tier; heavier use needs billing on your Google Cloud project. Usage Logs shows every request.

= Can I correct a summary? =
Yes. Open View / Edit on the Products tab, change any field and click "Save & lock". Locked summaries are never replaced automatically; "Regenerate" asks before it unlocks one.

= Who can manage summaries? =
Users with `manage_woocommerce` (shop managers and administrators) can generate, edit and lock summaries. Only administrators (`manage_options`) change settings. Both can be changed with the `dev3rs_capability` and `dev3rs_manage_capability` filters.

= Does it add star ratings to Google results? =
No. The plugin never outputs structured data for its summary. WooCommerce's own review markup is untouched.

= My theme doesn't show it in the chosen position =
Use the shortcode or the block in your product template and set the position to "Off".

== Privacy ==

This plugin sends product review data to a third-party service, the **Google Gemini API** (generativelanguage.googleapis.com, operated by Google), to generate summaries.

* **What is sent:** the product name and category, and for each approved review its text, star rating, month and whether it is from a verified owner.
* **What is never sent:** reviewer names, email addresses, IP addresses, user agents or account details. Email addresses, phone numbers and links inside review text are redacted before sending.
* **When:** only when a summary is generated: automatically after review changes (if enabled), during the nightly refresh (if enabled), or when an admin clicks Generate/Regenerate.
* **Your key:** requests use your own API key, under your agreement with Google (https://ai.google.dev/gemini-api/terms, https://policies.google.com/privacy). The key is encrypted in your database (AES-256-GCM) and never sent to the browser.
* **Stored data:** summaries are stored in product meta (`_dev3rs_summary`, `_dev3rs_locked`); usage logs (task, model, tokens, user ID) in the `dev3rs_usage` table. Enable "Delete all plugin data when uninstalling" to remove everything.

Consider mentioning AI-generated review summaries in your store's privacy policy.

== Screenshots ==

1. Summary card above the reviews list.
2. Compact layout below the Add to cart button.
3. Products screen: status, regenerate, bulk generate with progress.
4. Edit and lock a summary.
5. Display settings with live preview.

== Changelog ==

= 1.0.0 =
* Initial release.
