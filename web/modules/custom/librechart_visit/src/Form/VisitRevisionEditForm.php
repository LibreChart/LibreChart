<?php

declare(strict_types=1);

namespace Drupal\librechart_visit\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit form for a specific, historical Visit revision.
 *
 * Renders the standard Visit edit form populated with the values of a chosen
 * past revision (resolved from the {visit_revision} route parameter rather than
 * the default revision). Saving writes those — possibly further edited — values
 * as a new default revision, i.e. a restore-with-edits, preserving the complete
 * history. The concurrency reconciliation in the parent VisitForm still
 * applies, so a non-admin can only restore the fields their station owns.
 */
class VisitRevisionEditForm extends VisitForm {

  /**
   * The date formatter, for the revision-age notice and log message.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    DateFormatterInterface $date_formatter,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Resolve the form entity from the revision route parameter on every request
   * (GET and POST), so the edited entity is always the selected revision and
   * never the default one.
   */
  public function getEntityFromRouteMatch(RouteMatchInterface $route_match, $entity_type_id) {
    $revision = $route_match->getParameter('visit_revision');
    if ($revision instanceof ContentEntityInterface) {
      return $revision;
    }
    return parent::getEntityFromRouteMatch($route_match, $entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // Make it unmistakable that this is an earlier revision, not the live
    // record. The default (current) revision needs no warning — editing it is
    // ordinary editing.
    if (!$this->entity->isDefaultRevision()) {
      $user = $this->entity->getRevisionUser();
      $form['revision_edit_notice'] = [
        '#type' => 'container',
        '#weight' => -1000,
        '#attributes' => [
          'class' => ['messages', 'messages--warning'],
          'role' => 'alert',
        ],
        'text' => [
          '#markup' => $this->t('You are editing an <strong>earlier revision</strong> of this visit, saved on @date@by. Saving will restore these values as the new current version.', [
            '@date' => $this->dateFormatter->format((int) $this->entity->getRevisionCreationTime(), 'short'),
            '@by' => $user ? ' ' . $this->t('by @name', ['@name' => $user->getDisplayName()]) : '',
          ]),
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    // Capture the source revision's timestamp before save() stamps a new one.
    $source_time = (int) $this->entity->getRevisionCreationTime();

    // Persist the edited revision as a brand-new default revision so it becomes
    // the visit's current state. Visit::preSave() re-applies setNewRevision()
    // and records the editor/time; the parent VisitForm::save() reconciles
    // concurrent edits and aligns the optimistic-lock timestamp.
    $this->entity->setNewRevision(TRUE);
    $this->entity->isDefaultRevision(TRUE);
    if (trim((string) $this->entity->getRevisionLogMessage()) === '') {
      $this->entity->setRevisionLogMessage((string) $this->t('Restored from the revision of @date.', [
        '@date' => $this->dateFormatter->format($source_time, 'short'),
      ]));
    }

    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('The visit has been restored from the selected revision.'));

    // Return the user to the patient chart, where visits are viewed.
    $patient_id = $this->entity->get('patient')->target_id;
    if ($patient_id !== NULL && $patient_id !== '') {
      $form_state->setRedirect('entity.patient.edit_form', ['patient' => $patient_id]);
    }

    return $result;
  }

}
