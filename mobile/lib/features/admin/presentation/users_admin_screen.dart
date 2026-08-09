import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/fip_spacing.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';

/// People in the company.
///
/// Read-only here on purpose. `users.create` and `users.update` are granted to
/// a company administrator and enforced by `/admin/users`, but a create form on
/// a phone needs role selection, password policy and email verification to be
/// done properly — a half-built one that fails validation is worse than a list
/// that is honest about being a list.
class UsersAdminScreen extends ConsumerWidget {
  const UsersAdminScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final users = ref.watch(companyUsersProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Users'),
        leading: const FipBackButton(fallback: '/more'),
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(companyUsersProvider),
        child: users.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => ListView(
            padding: const EdgeInsets.all(FipSpace.page),
            children: [
              FipEmptyState(
                icon: Icons.lock_rounded,
                title: 'Users unavailable',
                message: 'This requires user administration access. $error',
                action: 'Retry',
                onAction: () => ref.invalidate(companyUsersProvider),
              ),
            ],
          ),
          data: (list) => list.isEmpty
              ? ListView(
                  padding: const EdgeInsets.all(FipSpace.page),
                  children: const [
                    FipEmptyState(
                      icon: Icons.group_rounded,
                      title: 'No users',
                      message: 'Nobody else is registered against this company yet.',
                    ),
                  ],
                )
              : ListView.separated(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(FipSpace.page),
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const SizedBox(height: FipSpace.gap),
                  itemBuilder: (context, i) => _UserRow(user: list[i]),
                ),
        ),
      ),
    );
  }
}

class _UserRow extends StatelessWidget {
  const _UserRow({required this.user});

  final Map<String, dynamic> user;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final roles = (user['roles'] as List<dynamic>? ?? []).join(', ');

    return FipCard(
      padding: const EdgeInsets.all(FipSpace.md + 2),
      child: Row(
        children: [
          CircleAvatar(
            radius: 18,
            backgroundColor: scheme.primary.withValues(alpha: 0.10),
            child: Text(
              (user['initials'] as String?) ?? '?',
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                color: scheme.primary,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          const SizedBox(width: FipSpace.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  (user['full_name'] as String?) ?? 'Unnamed',
                  style: Theme.of(
                    context,
                  ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
                ),
                Text(
                  roles.isEmpty ? ((user['email'] as String?) ?? '') : roles,
                  style: FipType.caption(context),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Requires `users.view`; the server enforces it and scopes to the tenant.
final companyUsersProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  final auth = ref.watch(authProvider);
  if (!auth.isAuthenticated) return const [];

  final api = ref.read(apiClientProvider);
  final response = await api.get<dynamic>('/admin/users', query: {'per_page': 100});
  final data = response is Map<String, dynamic> ? response['data'] ?? response : response;

  return (data as List<dynamic>).cast<Map<String, dynamic>>();
});
