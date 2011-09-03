SchemaCheck
===========

SchemaCheck is a script written in PHP to check for differences between a source MySQL database and
a target MySQL database. When differences are found SchemaCheck will spit out the sQL statements 
that should be run on the target database in order to make the schemas the same.

Because altering a dababases schema could lead to data loss, SchemaCheck does not automatically run
run the SQL to alter the target database.

Features
--------
* Provides SQL to bring two MySQL Databases into sync.
  * Creates SQL statements for:
    * Tables that have been added.
    * Tables that have been removed.
    * Columns that have been added.
    * Columns that have had their datatypes or defaults altered.
    * Columns that have been removed.
    
