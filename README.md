# Autofill

Autofill adds an AI-assisted field type to Craft CMS that helps editors generate, review, and apply field suggestions from entry content. Configure the fields you want Autofill to manage, add prompts and optional global context, then let editors generate suggested values from the entry edit page.

Full documentation is available at [jtdevelop.com/development/plugins/autofill](https://www.jtdevelop.com/development/plugins/autofill).

## Requirements

Autofill requires Craft CMS 5.0.0 or later and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Autofill”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require jt-dev/craft-autofill

# tell Craft to install the plugin
./craft plugin/install autofill
```
