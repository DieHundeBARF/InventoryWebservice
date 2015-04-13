# InventoryWebservice
Restful webservice to get the shops inventory

## To enable URL rewriting for apache:
+ `a2enmod rewrite`
+ in /etc/apache2/apache2.conf set `AllowOverride` to  `All` for the /var/www/ directory
+ `service apache2 restart`
