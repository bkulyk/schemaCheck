SchemaCheck
===========

SchemaCheck is a script written in PHP to check for differences between a source MySQL database and
a target MySQL database. When differences are found SchemaCheck will spit out the SQL statements 
that should be run on the target database in order to make the schemas the same.

Because altering a dababases schema could lead to data loss, SchemaCheck does not automatically run
the SQL to alter the target database.

Features
--------
* Provides SQL to bring two MySQL databases into sync.
  * Creates SQL statements for:
    * Tables that have been added.
    * Tables that have been removed.
    * Columns that have been added.
    * Columns that have had their datatypes or defaults altered.
    * Columns that have been removed.
    
Instructions
------------

On first use SchemaCheck will create a configuration file in your home directory called .schemaCheck.json.  
This file should contain configuration settings for schemaCheck as well as the database profiles for the
databases that will be compaired.

Once you have profiles defined in the config file, use the following command to run the comparison:

    php schemaCheck source_profile target_profile
    
