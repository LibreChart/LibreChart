<?php

declare(strict_types=1);

namespace Drupal\librechart_reports_user\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\librechart_reports_user\DashboardUserContext;
use Drupal\librechart_reports_user\UserActivityReport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders Chart.js visualisations of the target user's activity.
 */
#[Block(
  id: 'librechart_my_charts',
  admin_label: new TranslatableMarkup('My activity charts'),
  category: new TranslatableMarkup('Librechart'),
)]
final class MyChartsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly UserActivityReport $report,
    private readonly DashboardUserContext $userContext,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $data = $this->report->chartData($this->userContext->getTargetUserId());

    $cards = [
      'activity' => $this->t('My activity over time'),
      'specialty' => $this->t('My visits by specialty'),
      'diagnoses' => $this->t('My most frequent diagnoses'),
      'age' => $this->t('My patients by age group'),
      'sex' => $this->t('My patients by sex'),
    ];

    // Nothing to chart for a user who has not touched any visits yet.
    $has_data = FALSE;
    foreach ($cards as $key => $title) {
      if (!empty($data[$key]['labels'])) {
        $has_data = TRUE;
        break;
      }
    }
    if (!$has_data) {
      return [];
    }

    $grid = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-my-charts']],
      '#attached' => [
        'library' => ['librechart_reports_user/my_charts'],
        'drupalSettings' => [
          'librechartReportsUser' => ['charts' => $data],
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'route'],
        'tags' => ['visit_list'],
        'max-age' => 0,
      ],
    ];
    foreach ($cards as $key => $title) {
      $grid[$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['lc-chart-card', 'lc-chart-card--' . $key]],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#attributes' => ['class' => ['lc-chart-card__title']],
          '#value' => $title,
        ],
        'canvas_wrap' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['lc-chart-card__canvas-wrap']],
          'canvas' => [
            '#type' => 'html_tag',
            '#tag' => 'canvas',
            '#attributes' => [
              'class' => ['lc-chart-card__canvas'],
              'data-lc-user-chart' => $key,
            ],
            '#value' => '',
          ],
        ],
      ];
    }
    return $grid;
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
