# utility-class-cron-job-with-json-data
The utility PHP class that automatically: - Creates table in WP DB if not exists - Fetchs (daily cron / on admin demand via ajax) products in json format by HTTP request - Compares products by SKU + price with saved in DB - Inserts new / updates products if price changed - Sends email with list of new and/or changed prices
