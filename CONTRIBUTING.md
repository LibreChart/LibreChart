# Contributing to Drupal CMS

Drupal CMS is developed on [Drupal.org](https://www.drupal.org). We are grateful to the community for reporting bugs and contributing fixes and improvements. Drupal CMS's development repository lives at https://www.drupal.org/project/drupal_cms. You can also [join the `#drupal-cms-development` channel on Drupal Slack](https://drupal.slack.com/archives/C072BF486FN) to get help and ask questions.

Drupal CMS has adopted a [code of conduct](https://www.drupal.org/dcoc) that we expect all participants to adhere to.

## Getting Started

Drupal CMS is set up as a single repository, which is automatically split into multiple smaller projects. (See [monorepo.tools](https://monorepo.tools/) for more information on this approach.)

To contribute to Drupal CMS, you'll need [DDEV](https://ddev.com) version 1.25.0 or later (if you already have DDEV installed, run `ddev --version` to check the version). DDEV is the Drupal community's Docker-based development environment of choice and it sets up everything you need easily. [Install DDEV on your machine](https://ddev.com/get-started), then run the following commands to spin up the development branch of Drupal CMS:

```shell
git clone git@git.drupal.org:project/drupal_cms.git
cd drupal_cms
ddev launch
```

You can run Drush commands with `ddev drush`, and Composer commands with `ddev composer`. See [DDEV's documentation](https://docs.ddev.com/en/stable) for more information on using DDEV.

Issues and merge requests should be opened in the [Drupal CMS development issue queue](https://www.drupal.org/project/issues/drupal_cms).
