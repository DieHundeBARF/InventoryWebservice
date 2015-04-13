# InventoryWebservice
Restful webservice to get and adjust the shops inventory.

## Operations
+ Whole inventory (e.g. `http://<user>:<pass>@127.0.0.1/inventory`)
  + **HEAD** returns header without data (e.g. to check for service availability)
  + **GET** returns list of ids and names for every item formated as JSON
  + **POST name="Blättermagen" lieferant="Lunderland" ...** creates a new item

+ Single Item (e.g. `http://<user>:<pass>@127.0.0.1/inventory/67`)
  + **GET** returns database values for the item formated as JSON
  + **PUT quantity=5** adjusts quantity to 5
 
## Testing with curl
+ **HEAD:** `curl -u <user>:<pass> -i -X 'HEAD' http://127.0.0.1/inventory/`
+ **GET:** `curl -u <user>:<pass> http://127.0.0.1/inventory/67`
+ **PUT:** `curl -u <user>:<pass> -X PUT -d quantity=3 http://127.0.0.1/inventory/67`
+ **POST:** `curl -u <user>:<pass> --request 'POST' -d 'name=wurst' http://127.0.0.1/inventory/`

## Enabling URL rewriting for apache
1. `a2enmod rewrite`
2. In `apache2.conf` set `AllowOverride` to  `All` for the /var/www/ directory
3. `service apache2 restart`
