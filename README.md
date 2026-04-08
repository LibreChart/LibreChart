# Drupal CMS

Drupal CMS is a fast-moving open source product that enables site builders to easily create new Drupal sites and extend them with smart defaults, all using their browser.

## Getting started

If you want to use [DDEV](https://ddev.com) to run Drupal CMS locally, follow these instructions:

1. Install DDEV following the [documentation](https://ddev.com/get-started/)
2. Open the command line and `cd` to the root directory of this project
3. Run the following commands:
```shell
ddev config --project-type=drupal11 --docroot=web
ddev start
ddev composer install
ddev composer drupal:recipe-unpack
ddev launch
```

Drupal CMS has the same system requirements as Drupal core, so you can use your preferred setup to run it locally. [See the Drupal User Guide for more information](https://www.drupal.org/docs/user_guide/en/installation-chapter.html) on how to set up Drupal.

### Installation options

The Drupal CMS installer offers a list of features preconfigured with smart defaults. You will be able to customize whatever you choose, and add additional features, once you are logged in.

After the installer is complete, you will land on the dashboard.

## Documentation

* [Drupal CMS User Guide](https://project.pages.drupalcode.org/drupal_cms/)
* Learn more about managing a Drupal-based application in the [Drupal User Guide](https://www.drupal.org/docs/user_guide/en/index.html).

## Contributing & Support

[Report issues in the queue](https://drupal.org/node/add/project-issue/drupal_cms), providing as much detail as you can. You can also join the #drupal-cms-support channel in the [Drupal Slack community](https://www.drupal.org/slack).

Drupal CMS is developed in [a separate repository on Drupal.org](https://www.drupal.org/project/drupal_cms). See [CONTRIBUTING.md](CONTRIBUTING.md) for more information.

## License

Drupal CMS and all derivative works are licensed under the [GNU General Public License, version 2 or later](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

Learn about the [Drupal trademark and logo policy here](https://www.drupal.com/trademark).
