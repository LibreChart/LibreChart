<?php

declare(strict_types=1);

namespace Drupal\librechart_reports_user\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\librechart_reports_user\DashboardUserContext;
use Drupal\librechart_reports_user\StationFormatTrait;
use Drupal\librechart_reports_user\UserActivityReport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the patients the dashboard's target user has worked on.
 */
#[Block(
  id: 'librechart_my_patients',
  admin_label: new TranslatableMarkup('My patients'),
  category: new TranslatableMarkup('Librechart'),
)]
final class MyPatientsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use StationFormatTrait;

  /**
   * Constructs the block.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\librechart_reports_user\UserActivityReport $report
   *   The user activity report service.
   * @param \Drupal\librechart_reports_user\DashboardUserContext $userContext
   *   The dashboard user context resolver.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly UserActivityReport $report,
    private readonly DashboardUserContext $userContext,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('librechart_reports_user.activity'),
      $container->get('librechart_reports_user.user_context'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $uid = $this->userContext->getTargetUserId();
    $patients = $this->report->patientsEditedBy($uid);

    $header = [
      'name' => $this->t('Patient'),
      'age' => $this->t('Age'),
      'sex' => $this->t('Sex'),
      'status' => $this->t('Latest visit'),
      'edited' => $this->t('Last edited'),
    ];

    $rows = [];
    foreach ($patients as $patient) {
      $rows[] = [
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $patient['name'] !== '' ? $patient['name'] : $this->t('(no name)'),
            '#url' => Url::fromRoute('entity.patient.edit_form', ['patient' => $patient['pid']]),
          ],
        ],
        'age' => $patient['age'] ?? $this->t('—'),
        'sex' => $patient['sex'] ? ucfirst($patient['sex']) : $this->t('—'),
        'status' => $this->formatVisitStatus($patient['status'], $patient['current_station']),
        'edited' => $this->dateFormatter->format($patient['last_edited'], 'custom', 'M j, Y'),
      ];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('You have not worked on any patient records yet.'),
        '#attributes' => ['class' => ['lc-my-patients']],
      ],
      '#attached' => ['library' => ['librechart_reports_user/my_dashboard']],
      '#cache' => [
        'contexts' => ['user', 'route'],
        'tags' => ['visit_list', 'patient_list'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->hasPermission('view own dashboard')
      || $account->hasPermission('view any user dashboard')
    )->cachePerPermissions();
  }

}
