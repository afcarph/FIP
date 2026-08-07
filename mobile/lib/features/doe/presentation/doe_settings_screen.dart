import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/config/app_config.dart';
import '../data/doe_providers.dart';
import '../data/uat_settings.dart';

/// API URL, theme and version.
class DoeSettingsScreen extends ConsumerStatefulWidget {
  const DoeSettingsScreen({super.key});

  @override
  ConsumerState<DoeSettingsScreen> createState() => _DoeSettingsScreenState();
}

class _DoeSettingsScreenState extends ConsumerState<DoeSettingsScreen> {
  late final TextEditingController _urlController;

  @override
  void initState() {
    super.initState();
    _urlController = TextEditingController(text: ref.read(uatSettingsProvider).apiBaseUrl);
  }

  @override
  void dispose() {
    _urlController.dispose();
    super.dispose();
  }

  /// Everything cached came from the old host, so all of it is now wrong.
  ///
  /// Listed exhaustively rather than per screen: dropping only the providers
  /// this screen happens to think of leaves the others showing the previous
  /// host's data — or its error — while the header quietly reloads, which
  /// reads as the app being half broken.
  void _reloadEverything() {
    ref.invalidate(doeHealthProvider);
    ref.invalidate(doeReportsProvider);
    ref.invalidate(doeLatestProvider);
    ref.invalidate(doeSearchProvider);
    ref.invalidate(doeTrendProvider);
    ref.invalidate(doeBrandsProvider);
  }

  Future<void> _save() async {
    await ref.read(uatSettingsProvider.notifier).setApiBaseUrl(_urlController.text);
    _reloadEverything();

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('API URL updated. Data reloaded from the new host.')),
    );
  }

  Future<void> _reset() async {
    await ref.read(uatSettingsProvider.notifier).setApiBaseUrl('');
    _urlController.text = AppConfig.apiBaseUrl;
    _reloadEverything();

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Back on the build default.')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final settings = ref.watch(uatSettingsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text('API', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          TextField(
            controller: _urlController,
            keyboardType: TextInputType.url,
            autocorrect: false,
            decoration: const InputDecoration(
              labelText: 'API base URL',
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              FilledButton(onPressed: _save, child: const Text('Save')),
              const SizedBox(width: 8),
              if (settings.isOverridden)
                TextButton(onPressed: _reset, child: const Text('Reset to build default')),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            settings.isOverridden
                // Worth saying plainly: a tester chasing "wrong data" should be
                // able to see at a glance that the app is not pointed where the
                // build pointed it.
                ? 'Overridden on this device. The build default is ${AppConfig.apiBaseUrl}.'
                : 'Using the build default.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (AppConfig.usingEmulatorDefault) ...[
            const SizedBox(height: 8),
            Card(
              color: Theme.of(context).colorScheme.errorContainer,
              child: const Padding(
                padding: EdgeInsets.all(12),
                child: Text(
                  'This build points at the Android emulator alias (10.0.2.2), which is '
                  'unreachable from a physical device. Set the staging URL above.',
                ),
              ),
            ),
          ],
          const Divider(height: 32),
          Text('Appearance', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          SegmentedButton<ThemeMode>(
            segments: const [
              ButtonSegment(value: ThemeMode.system, label: Text('System')),
              ButtonSegment(value: ThemeMode.light, label: Text('Light')),
              ButtonSegment(value: ThemeMode.dark, label: Text('Dark')),
            ],
            selected: {settings.themeMode},
            onSelectionChanged: (selection) =>
                ref.read(uatSettingsProvider.notifier).setThemeMode(selection.first),
          ),
          const Divider(height: 32),
          const ListTile(
            contentPadding: EdgeInsets.zero,
            leading: Icon(LucideIcons.info),
            title: Text('Version'),
            subtitle: Text('${AppConfig.appName} · UAT build'),
          ),
          const ListTile(
            contentPadding: EdgeInsets.zero,
            leading: Icon(LucideIcons.database),
            title: Text('Data source'),
            subtitle: Text(
              'Department of Energy weekly price monitoring publications, '
              'ingested from prod-cms.doe.gov.ph.',
            ),
          ),
        ],
      ),
    );
  }
}
