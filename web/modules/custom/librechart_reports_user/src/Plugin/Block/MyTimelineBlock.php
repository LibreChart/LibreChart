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
 * Shows the target user's most recent edits as a timeline.
 */
#[Block(
  id: 'librechart_my_timeline',
  admin_label: new TranslatableMarkup('My recent edits'),
  category: new TranslatableMarkup('Librechart'),
)]
final class MyTimelineBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    $edits = $this->report->recentEdits($this->userContext->getTargetUserId());

    if ($edits === []) {
      return [
        '#markup' => '<p class="lc-timeline__empty">' . $this->t('No recent edits.') . '</p>',
        '#attached' => ['library' => ['librechart_reports_user/my_dashboard']],
        '#cache' => [
          'contexts' => ['user', 'route'],
          'tags' => ['visit_list'],
          'max-age' => 0,
        ],
      ];
    }

    $items = [];
    foreach ($edits as $edit) {
      $name = $edit['name'] !== '' ? $edit['name'] : (string) $this->t('(no name)');
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<span class="lc-timeline__time">{{ time }}</span>{{ link }}<span class="lc-timeline__meta">{{ meta }}</span>',
        '#context' => [
          'time' => $this->dateFormatter->format($edit['ts'], 'custom', 'M j, g:i a'),
          'link' => [
            '#type' => 'link',
            '#title' => $name,
            '#url' => Url::fromRoute('entity.visit.canonical', ['visit' => $edit['vid']]),
            '#attributes' => ['class' => ['lc-timeline__patient']],
          ],
          'meta' => $this->formatVisitStatus($edit['status'], $edit['current_station']),
        ],
        '#wrapper_attributes' => ['class' => ['lc-timeline__item']],
      ];
    }

    return [
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['lc-timeline']],
      ],
      '#attached' => ['library' => ['librechart_reports_user/my_dashboard']],
      '#cache' => [
        'contexts' => ['user', 'route'],
        'tags' => ['visit_list'],
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
