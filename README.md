# RETS WP
This is a wordpress plugin that uses the [phrets](https://github.com/troydavisson/PHRETS) library to connect to an MLS association's listings database using the RETS protocol and preform searches. It stores config data in the wp_options table (saved as "rets-config"), such as server url and login info. This plugin is meant to be mostly used in theme files, for example:
```php
$search = trim(@$_GET['search']);
$props = apply_filters('rets-search', array(), $search); // perform a search against the mls server

foreach($props as $prop) {
  // $prop will have several fields implemented by the MLS server, like # of beds/baths, sq.ft. of home, etc.
  $mlsid = trim(@$prop->MLSNumber);
  echo $mlsid;
}
```
