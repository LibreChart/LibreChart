<?php

declare(strict_types=1);

namespace Drupal\librechart_reports_user\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\librechart_reports_user\DashboardUserContext;
use Drupal\librechart_reports_user\UserActivityReport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows headline counts for the dashboard's target user.
 */
#[Block(
  id: 'librechart_my_stats',
  admin_label: new TranslatableMarkup('My activity summary'),
  category: new TranslatableMarkup('Librechart'),
)]
final class MyStatsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    $stats = $this->report->summaryStats($this->userContext->getTargetUserId());

    $cards = [
      [
        'value' => $stats['patients'],
        'label' => $this->t('Patients worked on'),
        'sub' => $this->t('Distinct patients you have touched'),
      ],
      [
        'value' => $stats['visits'],
        'label' => $this->t('Visits touched'),
        'sub' => $this->t('Visit records you have edited'),
      ],
      [
        'value' => $stats['today_patients'],
        'label' => $this->t('Seen this mission day'),
        'sub' => $this->missionDayLabel($stats['mission_day']),
      ],
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lc-my-stats']],
      '#attached' => ['library' => ['librechart_reports_user/my_dashboard']],
      '#cache' => [
        'contexts' => ['user', 'route'],
        'tags' => ['visit_list'],
        'max-age' => 0,
      ],
    ];
    foreach ($cards as $i => $card) {
      $build[$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['lc-stat-card']],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['lc-stat-card__value']],
          '#value' => $card['value'],
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['lc-stat-card__label']],
          '#value' => $card['label'],
        ],
        'sub' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['lc-stat-card__sub']],
          '#value' => $card['sub'],
        ],
      ];
    }
    return $build;
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

  /**
   * Formats the mission-day sub-label for the "seen today" card.
   *
   * @param string|null $day
   *   The mission day (YYYY-MM-DD), or NULL when no visits exist.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sub-label.
   */
  private function missionDayLabel(?string $day): TranslatableMarkup {
    if ($day === NULL) {
      return $this->t('No visits recorded yet');
    }
    $date = DrupalDateTime::createFromFormat('Y-m-d', $day);
    return $this->t('Mission day: @date', [
      '@date' => $date ? $date->format('M j, Y') : $day,
    ]);
  }

}
