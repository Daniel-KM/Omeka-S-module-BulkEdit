Bulk Edit (module for Omeka S)
==============================

[Bulk Edit] is a module for [Omeka S] that adds tools to bulk edit resources in
order to modify or to clean them.

Current processes are:
- Replace value of a property (directly or via regex)
- Prepend or append a string to a value of a property
- Set or remove language of a property
- Set visibility of a property public or private
- Remove all trailing white spaces
- Remove duplicate values

Furthermore, values are automatically trimmed and deduplicated when a resource
is saved.


Installation
------------

Uncompress files and rename plugin folder `BulkEdit`.

See general end user documentation for [Installing a module] and follow the
config instructions.


Usage
-----

The tool is available via the standard bulk process in Admin > Items, Admin > Item Sets,
and Admin > Media. Simply select specific resources or all, then click Go, then
select and config the process to do.

The job is launched directly when specific resources are selected, and in the
background when all resources are selected.

### Replace value of a property directly or via regex

Fill fields "Replace" and "By", specify the type of replacement (raw or regex), 
then select the properties to update.

### Prepend or append a string

Fill fields "Prepend" and/or "Append" and select the properties to update.

### Set or remove language of a property

Select the properties to set or remove language.
Note: all values of the selected properties are updated, so be aware of existing
languages when they are multiple.

### Set or unset visibility of a property

Select the properties to set or unset visibility.

### Trim property values

Remove leading and trailing whitespaces preventively on any resource creation or
update, or curatively via the batch edit, so values will be easier to find and
to compare exactly (fix [#1258]).

### Deduplicate property values

Remove exact duplicated values on any new or updated resource preventively.
Note: preventive deduplication is case sensitive, but curative deduplication is
case insensitive (it uses a direct query and the Omeka database is case
insensitive by default).


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

* Copyright Daniel Berthereau, 2018-2019 (see [Daniel-KM])

First developed for the [Archives Henri Poincaré] of [Université de Lorraine].


[Bulk Edit]: https://github.com/Daniel-KM/Omeka-S-module-BulkEdit
[Omeka S]: https://omeka.org/s
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-BulkEdit/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Archives Henri Poincaré]: https://poincare.univ-lorraine.fr
[Université de Lorraine]: https://www.univ-lorraine.fr
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
