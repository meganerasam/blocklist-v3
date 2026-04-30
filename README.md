# Blocklist Automation Architecture

Welcome to the Automated All-In-One Blocklist project! This directory serves as the master compiler for converting, sorting, and merging multiple ad-blocking lists into a format easily digestible by web extension engines (Declarative Net Request and CSS injection).

## 📁 Directory Structure

The project is structured into **Category Folders** and a master **All-In-One** compiler folder.

### 1. Category Folders
There are four primary category folders, each focusing on a specific type of blocking:
*   `adult/` - Rules tailored for blocking or handling adult-oriented content.
*   `easylist/` - General ad-blocking rules (the core standard).
*   `easyprivacy/` - Tracking, telemetry, and privacy-invading rules.
*   `fanboy/` - Social media annoyances and other annoyances.

Inside **every single category folder**, you will find three subcategories that categorize the *type* of rule being processed:
*   `/allow/` - Whitelists, exception rules, and selectors that should *not* be hidden or blocked.
*   `/block/` - Blacklists, domain blocks, and URL filters intended to be stopped at the network level.
*   `/css/` - Cosmetic hiding rules (DOM elements) intended to be injected as stylesheets into the webpage.

Each of these subcategories contains a generation script (e.g., `generate_allow.php`, `generate_dnr.php`, `generate_css.php`) that parses raw sources and outputs extension-ready JSON and CSS files for that specific category.

### 2. The `all-in-one` Folder
The `all-in-one` directory is the master output folder. It acts as an aggregator that takes the generated lists from all four categories and merges them together perfectly.

It contains four subfolders:
*   `allow/` - Merges `network.json`, `popup.json`, and `unhide.json` from all categories.
*   `block/` - Merges `domains.json`, `popup.json`, and `urlfilter.json` from all categories.
*   `css/` - Merges `generic.css`, `specific.json`, and `extended.json` from all categories, completely deduplicating CSS selectors to optimize performance.
*   `merged-dnr/` - The ultimate compiler. It takes all the network rules from the `all-in-one/allow` and `all-in-one/block` directories and merges them into a single, massive `dnr.json` file. It automatically re-indexes every single rule's ID from 1 to 10,000+ to ensure there are zero conflicts in the browser engine.

---

## 🚀 How to Add a New Source

If you find a new blocklist or whitelist source that you want to include in the project, follow these steps:

### Step 1: Identify the Category and Subcategory
Determine what kind of list it is:
*   Is it blocking telemetry? Go to `easyprivacy`.
*   Is it a list of domains to block? Go to the `block` subcategory.
*   Is it a list of CSS selectors to hide? Go to the `css` subcategory.

*(Example: Adding a new social media blocklist -> `fanboy/block/`)*

### Step 2: Update the Generator Script
Navigate into the chosen subcategory and open the local generation script (e.g., `generate_dnr.php`). 
Inside this PHP file, you will find the logic where it fetches URLs or reads local files. Add your new source URL or file path to its fetch list.

### Step 3: Compile the Master List
You do **not** need to manually run the scripts in each folder. Instead, you can run the master synchronization scripts to cascade the update everywhere.

1. Open your terminal in the `all-in-one/` directory.
2. Run the master generation script:
   ```bash
   php generate_all.php
   ```
   *This will automatically reach into all category folders, run their individual generators to pull down your new source, and merge the updated JSON/CSS files into the `all-in-one` folder.*

3. Run the master DNR merger:
   ```bash
   cd merged-dnr
   php merge_dnr.php
   ```
   *This will take the freshly updated network files and compile them into your final `dnr.json` file.*

You are now ready to deploy your updated lists!
