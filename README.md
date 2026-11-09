Bulk Edit (module for Omeka S)
==============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Bulk Edit] is a module for [Omeka S] that adds tools to bulk edit resources in
order to modify or to clean them.

The batch edit page of Omeka gets a new tab "Advanced", with these actions:

- Values
  - Create values in multiple properties, with any data type and language
  - Modify values: replace text, set or change the language, explode values,
    convert the data type, sort by language and set the visibility
  - Copy or move values to another property
  - Merge two values into one uri with a label
  - Fill labels or uris from remote services (module [Value Suggest])
  - Remove values
  - Clean values: trim spaces, remove empty values and duplicates
- Resource
  - Set or remove the owner
  - Attach or remove a thumbnail
- Media
  - Modify the html, the source, the media type and the visibility of media
  - Remove media
  - Sort media
  - Explode an item into one item by media, or a pdf into images

A preview shows the values before and after the changes, without saving.

Furthermore, values are automatically trimmed and deduplicated when a resource
is saved.

The slow processes are never run during a web request. They are run in a
background job: explode items and pdf, fill values from remote services, convert
to places or coordinates, and the processes on media of items.


Installation
------------

### Module

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

* From the zip

Download the last release [BulkEdit.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `BulkEdit`.

Then install it like any other Omeka module and follow the config instructions.

### Libraries

If you want to explode a pdf, you need either the command `pdftoppm` (poppler)
(recommended) or `gs` (ghostscript).

* For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/BulkEdit/phpunit.xml --testdox
```


Usage
-----

The tool is available via the standard batch edit in Admin > Items, Admin > Item
Sets and Admin > Media: select some resources or all of them, choose "Edit
selected" or "Edit all", then open the tab "Advanced".

The resources managed by some modules are supported too: annotations (module
[Annotate]), concepts (module [Thesaurus]) and digital objects (module
[Digital Object]). For digital objects, the available processes are the same as
for media.

To find the resources to edit, the module [Advanced Search] allows to search by
creation date, modification date or visibility.

### How it works

- Each action is a section that can be opened. A green check is displayed when
  the action will do something.
- The actions are processed in the order of the page.
- The filters are the same for all actions: data types, languages, visibility,
  a string that the value equals or contains, a regex, and the parts of the value
  to search in (text, uri, label of the uri, title of the linked resource). An
  empty filter means all values. By default, the linked resources are not
  checked by the strings.
- The options of an action are displayed when its main field is filled.
- The section "Preview" at the end of the page shows the first updated
  resources, ten by page, with the values before and after.

### Values

#### Create

Add a property, then add values with the same editor than the resource form:
text, uri, linked resource or any other data type, with a language and a
visibility. Multiple values can be added in multiple properties.

#### Modify

Select the properties of the values to modify, filter them if needed, then check
the operations to apply, in this order:

- Modify the text: search and replace (exact, case insensitive, regex or html
  entities), replace the whole value, keep the file name only, fix the encoding,
  or remove the value. A string may be prepended or appended too.
- Modify the language: set it, remove it, or change a code into another one,
  for example "fr" into "fra".
- Explode the values with a separator, for example after a csv import.
- Convert the data type, for example a text into an uri or a linked resource.
- Sort by language, for example to display the title in a language first.
- Modify the visibility.

The whole value, the prepended and the appended strings support placeholders:
`{resource_id}`, `{resource_url}`, `{resource_url_admin}`, `{resource_title}`,
`{main_url}`, and any property term, like `{dcterms:identifier}`. So, to set the
uri of a property to the url of the resource, choose the part "Uri", the
operation "Replace the whole value" and `{resource_url}`.

Examples of regex:
- Convert spaces into "-" for identifiers: pattern = `~\s+~`, replacement = `-`.
- Convert `D:20220908101423` into a date: pattern = `~^D:(\d{4})(\d{2})(\d{2})~`,
  replacement = `$1-$2-$3`.

#### Copy and displace

Copy or move the values of some properties to another one. The data type may be
changed at the same time, without conversion.

#### Merge as uri

The values are merged two by two as an uri with a label. The number of values
must be even and at least one value of each pair should be an url.

#### Value Suggest

Fill the missing labels or uris of the values from remote services of the module
[Value Suggest], or remove the labels. The uri is filled only when the service
returns a single result.

#### Remove

Remove the values of the properties, all of them or the filtered ones. A
number of values to keep or to remove can be set.

#### Cleaning

Trim spaces, set the right data type of linked resources, remove the empty
values and languages, and remove the duplicate values. These cleanings are
processed after the other actions.

### Resource

Set or remove the owner, and attach or remove a thumbnail, for example only
when there is no thumbnail.

### Media

#### Modify media

Select the media by media type, ingester or renderer, then check the operations:
modify the html, the source (for example to remove the path of imported files),
the media type, or the visibility.

#### Remove, sort and explode

Remove media by media type or extension, sort the media of items, and explode an
item into one item by media or a pdf into images (useful for iiif viewers). To
explode a pdf, the command `pdftoppm` (poppler) or `gs` (ghostscript) is
required.


TODO
----

- [x] Add conversion for custom vocab.
- [ ] Normalize custom vocab values.
- [ ] Include collection, classes and templates or even full first tab?
- [ ] Move hard-coded xml xpath into form.
- [ ] Fill all missing value suggests.
- [ ] Store common batches via a single checkbox and a simple select in the top next times.
- [ ] Recommend [History Log].


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

This module is published under the [CeCILL v2.1] license, compatible with
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

* Copyright Daniel Berthereau, 2018-2026 (see [Daniel-KM])

First developed for the [Archives Henri Poincaré] of [Université de Lorraine].
Then, improvements was done for the maintenance of various sites.


[Bulk Edit]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkEdit
[Omeka S]: https://omeka.org/s
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Advanced Search]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedSearch
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[BulkEdit.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkEdit/-/releases
[Annotate]: https://gitlab.com/Daniel-KM/Omeka-S-module-Annotate
[Thesaurus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus
[Digital Object]: https://gitlab.com/Daniel-KM/Omeka-S-module-DigitalObject
[Value Suggest]: https://github.com/omeka-s-modules/ValueSuggest
[History Log]: https://gitlab.com/Daniel-KM/Omeka-S-module-HistoryLog
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkEdit/-/work_items
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Archives Henri Poincaré]: https://poincare.univ-lorraine.fr
[Université de Lorraine]: https://www.univ-lorraine.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
