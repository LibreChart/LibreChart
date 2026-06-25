<?php

declare(strict_types=1);

namespace Drupal\librechart_reports_user;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves which user a dashboard widget should report on.
 *
 * On the personal dashboard there is no user in the route, so widgets report
 * on the current user. When a privileged user views another account's
 * dashboard (a route carrying a "user" parameter), widgets report on that
 * account instead, provided the viewer holds the oversight permission.
 */
final class DashboardUserContext {

  /**
   * Constructs the context resolver.
   *
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Returns the user id the dashboard should report on.
   *
   * @return int
   *   The target user id; the current user unless a privileged viewer is
   *   looking at another account's dashboard.
   */
  public function getTargetUserId(): int {
    $param = $this->routeMatch->getParameter('user');
    if ($param !== NULL && $this->currentUser->hasPermission('view any user dashboard')) {
      if ($param instanceof AccountInterface) {
        return (int) $param->id();
      }
      if (is_numeric($param)) {
        return (int) $param;
      }
    }
    return (int) $this->currentUser->id();
  }

  /**
   * Whether the dashboard is showing someone other than the current user.
   *
   * @return bool
   *   TRUE when viewing another account's dashboard.
   */
  public function isViewingOther(): bool {
    return $this->getTargetUserId() !== (int) $this->currentUser->id();
  }

}
