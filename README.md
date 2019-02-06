# vestacp-ispdnsmanager-updater
This script will list all domains and will update ISPDNSMANAGER via API (slave mode).

Crontab entry. You need root to execute vesta scripts. You can go as admin user and pre-sudo command, but it doesnt make it less secure.

>*/30 * * * *    root    php /opt/dns_hosting_api_updater.php

