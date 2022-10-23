# forge-site-backup
Run the following via cron (or whatever) as often as you want
php8.1 /home/forge/site-backup/site-backup.php

Configure via .env:
s3_endpoint="your-s3-endpoint"
s3_bucket="your-s3-bucket"
s3_access_key="your-s3-access-key"
s3_secret_key="your-s3-secret-key"
storage_directory="/path/in/bucket" (no trailing slash)
sites="somesite.com,someothersite.com" (no slashes)
backups_to_keep=2
