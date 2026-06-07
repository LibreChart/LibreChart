<?php

declare(strict_types=1);

namespace Drupal\librechart_pharmacy\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Minimal form to edit a drug from the inventory report.
 *
 * A drug's name is the label of a taxonomy term in one of the medication
 * vocabularies (feature 005); this form exposes that name — none of the other
 * taxonomy-term fields — so pharmacists can correct a drug name in one step.
 * Renaming updates the drug everywhere it is referenced. Users who may also
 * edit inventory get a low-stock threshold field, saved to the drug's primary
 * (lowest-id) drug_inventory record — the same record the report displays.
 */
class DrugRenameForm extends FormBase {

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'librechart_pharmacy_drug_rename';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param \Drupal\taxonomy\TermInterface|null $taxonomy_term
   *   The drug term to edit, upcast from the route parameter.
   *
   * @return array
   *   The edit form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?TermInterface $taxonomy_term = NULL): array {
    // Guard against editing non-drug terms (clinic sites, pharmacist names)
    // reached by tampering with the term id in the URL.
    if ($taxonomy_term === NULL
      || !array_key_exists($taxonomy_term->bundle(), librechart_pharmacy_drug_vocabularies())) {
      throw new NotFoundHttpException();
    }

    $form_state->set('term_id', $taxonomy_term->id());

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Drug name'),
      '#default_value' => $taxonomy_term->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    // The category is the drug's medication vocabulary; changing it moves the
    // term to another vocabulary in place (the term id, and so every reference
    // to it, is preserved).
    $form['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => librechart_pharmacy_drug_vocabularies(),
      '#default_value' => $taxonomy_term->bundle(),
      '#required' => TRUE,
    ];

    // Offer the low-stock threshold only to users who may edit inventory, and
    // only when a record exists. It is saved to the drug's primary (lowest-id)
    // record — the one the inventory report reads.
    $account = $this->currentUser();
    $may_edit_inventory = $account->hasPermission('edit any drug_inventory entities')
      || $account->hasPermission('administer drug_inventory entities');
    if ($may_edit_inventory && ($inventory = $this->primaryInventory($taxonomy_term->id())) !== NULL) {
      $form['low_stock_threshold'] = [
        '#type' => 'number',
        '#title' => $this->t('Low-stock threshold'),
        '#description' => $this->t('Quantity at or below which this drug is flagged as low stock.'),
        '#min' => 0,
        '#default_value' => (int) $inventory->get('low_stock_threshold')->value,
        '#required' => TRUE,
      ];
      $form_state->set('inventory_id', $inventory->id());
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('librechart_pharmacy.report'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->load($form_state->get('term_id'));
    $term->setName(trim((string) $form_state->getValue('name')));
    $term->set('vid', $form_state->getValue('category'));
    $term->save();

    $inventory_id = $form_state->get('inventory_id');
    if ($inventory_id !== NULL) {
      $inventory = $this->entityTypeManager
        ->getStorage('drug_inventory')
        ->load($inventory_id);
      if ($inventory instanceof ContentEntityInterface) {
        $inventory->set('low_stock_threshold', (int) $form_state->getValue('low_stock_threshold'));
        $inventory->save();
      }
    }

    $this->messenger()->addStatus(
      $this->t('Saved changes to %name.', ['%name' => $term->label()]),
    );
    $form_state->setRedirect('librechart_pharmacy.report');
  }

  /**
   * Loads the primary (lowest-id) inventory record for a drug term.
   *
   * @param int|string $term_id
   *   The drug taxonomy term id.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The primary drug_inventory record, or NULL if the drug has none.
   */
  private function primaryInventory(int|string $term_id): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('drug_inventory');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('drug', $term_id)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();

    $inventory = $ids ? $storage->load(reset($ids)) : NULL;

    return $inventory instanceof ContentEntityInterface ? $inventory : NULL;
  }

}
