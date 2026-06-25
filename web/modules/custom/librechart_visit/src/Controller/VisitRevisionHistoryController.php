<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\Controller\VersionHistoryController;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Link;

/**
 * Visit revision overview that links each revision to its editable display.
 *
 * Core's revision overview links each revision date to the read-only revision
 * view. Clinicians want to open a past revision in the familiar Visit edit
 * layout (and optionally restore it), so this controller points the link at the
 * 'revision-edit' route, falling back to the read-only view when the user has
 * no edit access.
 */
class VisitRevisionHistoryController extends VersionHistoryController {

  /**
   * {@inheritdoc}
   *
   * Mirrors the parent implementation but prefers the editable revision link.
   */
  protected function getRevisionDescription(RevisionableInterface $revision): array {
    $context = [];
    if ($revision instanceof RevisionLogInterface) {
      ['type' => $dateFormatType, 'format' => $dateFormatFormat] = $this->getRevisionDescriptionDateFormat($revision);
      $linkText = $this->dateFormatter->format($revision->getRevisionCreationTime(), $dateFormatType, $dateFormatFormat);
      $context['username'] = [
        '#theme' => 'username',
        '#account' => $revision->getRevisionUser(),
      ];
    }
    else {
      $linkText = $revision->access('view label') ? $revision->label() : $this->t('- Restricted access -');
    }

    // Prefer the editable revision display; fall back to the read-only revision
    // view when the user cannot edit (or no edit template exists).
    $url = NULL;
    if ($revision->hasLinkTemplate('revision-edit')) {
      $editUrl = $revision->toUrl('revision-edit');
      if ($editUrl->access()) {
        $url = $editUrl;
      }
    }
    if ($url === NULL && $revision->hasLinkTemplate('revision')) {
      $viewUrl = $revision->toUrl('revision');
      if ($viewUrl->access()) {
        $url = $viewUrl;
      }
    }

    $context['revision'] = $url
      ? Link::fromTextAndUrl($linkText, $url)->toString()
      : (string) $linkText;
    $context['message'] = $revision instanceof RevisionLogInterface ? [
      '#markup' => $revision->getRevisionLogMessage(),
      '#allowed_tags' => Xss::getHtmlTagList(),
    ] : '';

    return [
      'data' => [
        '#type' => 'inline_template',
        '#template' => isset($context['username'])
          ? '{% trans %} {{ revision }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}'
          : '{% trans %} {{ revision }} {% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
        '#context' => $context,
      ],
    ];
  }

}
