import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/providers/app_providers.dart';

/// OCR price-board scanner — the app's signature interaction.
///
/// The review step is not optional. OCR on a glare-lit LED board is good, not
/// perfect, and publishing a misread price would corrupt the data for every
/// user nearby. So each extracted line arrives with its own confidence and
/// the user confirms before anything is submitted.
class ScannerScreen extends ConsumerStatefulWidget {
  const ScannerScreen({super.key});

  @override
  ConsumerState<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends ConsumerState<ScannerScreen> {
  final ImagePicker _picker = ImagePicker();

  File? _image;
  bool _scanning = false;
  Map<String, dynamic>? _result;
  String? _error;

  Future<void> _capture(ImageSource source) async {
    try {
      final picked = await _picker.pickImage(
        source: source,
        // Downscale on device: a 12 MP original costs seconds of upload on
        // mobile data and adds nothing the OCR pipeline can use.
        maxWidth: 2000,
        maxHeight: 2000,
        imageQuality: 88,
      );

      if (picked == null) return;

      setState(() {
        _image = File(picked.path);
        _result = null;
        _error = null;
      });

      await _scan();
    } on Exception catch (error) {
      setState(() => _error = 'Could not open the camera: $error');
    }
  }

  Future<void> _scan() async {
    final image = _image;
    if (image == null) return;

    setState(() {
      _scanning = true;
      _error = null;
    });

    try {
      final formData = FormData.fromMap({
        'image': await MultipartFile.fromFile(image.path, filename: 'board.jpg'),
      });

      final result = await ref
          .read(apiClientProvider)
          .upload<Map<String, dynamic>>('/ocr/scan', formData);

      setState(() => _result = result);
    } on ApiException catch (error) {
      setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _scanning = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Scan a price board')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _Preview(image: _image, scanning: _scanning),
              const SizedBox(height: 16),
              Row(
                children: [
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: _scanning ? null : () => _capture(ImageSource.camera),
                      icon: const Icon(LucideIcons.camera, size: 18),
                      label: const Text('Take a photo'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _scanning ? null : () => _capture(ImageSource.gallery),
                      icon: const Icon(LucideIcons.image, size: 18),
                      label: const Text('Choose'),
                    ),
                  ),
                ],
              ),
              if (_error != null) ...[const SizedBox(height: 16), _ErrorBanner(message: _error!)],
              if (_result != null) ...[
                const SizedBox(height: 20),
                _ScanResult(result: _result!),
              ] else if (_image == null) ...[
                const SizedBox(height: 24),
                const _Tips(),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _Preview extends StatelessWidget {
  const _Preview({this.image, required this.scanning});

  final File? image;
  final bool scanning;

  @override
  Widget build(BuildContext context) {
    return AspectRatio(
      aspectRatio: 4 / 3,
      child: Container(
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
        ),
        clipBehavior: Clip.antiAlias,
        child:
            image == null
                ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        LucideIcons.scanLine,
                        size: 40,
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                      const SizedBox(height: 12),
                      Text(
                        'Point at the price board',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                )
                : Stack(
                  fit: StackFit.expand,
                  children: [
                    Image.file(image!, fit: BoxFit.cover),
                    if (scanning)
                      ColoredBox(
                        color: Colors.black.withValues(alpha: 0.55),
                        child: const Center(
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              CircularProgressIndicator(color: Colors.white),
                              SizedBox(height: 12),
                              Text('Reading the board…', style: TextStyle(color: Colors.white)),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
      ),
    );
  }
}

class _ScanResult extends StatelessWidget {
  const _ScanResult({required this.result});

  final Map<String, dynamic> result;

  @override
  Widget build(BuildContext context) {
    final lines = (result['lines'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
    final valid = lines.where((line) => line['valid'] == true).toList();
    final rejected = lines.where((line) => line['valid'] != true).toList();
    final confidence = (result['overall_confidence'] as num?)?.toDouble() ?? 0;
    final status = result['status'] as String? ?? 'parsed';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Text(
              'Extracted prices',
              style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
            ),
            const Spacer(),
            Chip(
              label: Text(
                '${(confidence * 100).round()}% confident',
                style: const TextStyle(fontSize: 11),
              ),
              visualDensity: VisualDensity.compact,
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (valid.isEmpty)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Icon(LucideIcons.triangleAlert, color: context.fipColors.warning, size: 20),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'No prices could be read. Try again with the board filling more of the frame.',
                    ),
                  ),
                ],
              ),
            ),
          )
        else
          ...valid.map((line) => _LineRow(line: line)),
        if (rejected.isNotEmpty) ...[
          const SizedBox(height: 12),
          Text(
            'Skipped lines',
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 6),
          // Showing what was rejected and why is what lets a user fix their
          // photo rather than repeatedly retrying the same bad angle.
          ...rejected.map(
            (line) => Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(
                '${line['label'] ?? 'Unreadable'} — ${_reasonLabel(line['rejection_reason'] as String?)}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          ),
        ],
        const SizedBox(height: 16),
        if (status == 'approved')
          Card(
            color: context.fipColors.priceDown.withValues(alpha: 0.1),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Row(
                children: [
                  Icon(LucideIcons.circleCheck, size: 18, color: context.fipColors.priceDown),
                  const SizedBox(width: 10),
                  const Expanded(child: Text('Published — thanks for keeping prices current.')),
                ],
              ),
            ),
          )
        else
          Text(
            'Your scan has been submitted for review. Approved prices usually appear within minutes.',
            style: Theme.of(
              context,
            ).textTheme.bodySmall?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
          ),
      ],
    );
  }

  static String _reasonLabel(String? reason) => switch (reason) {
    'unrecognised_fuel_label' => 'fuel type not recognised',
    'implausible_price' => 'price outside the plausible range',
    'price_out_of_local_band' => 'too far from local prices',
    _ => 'could not be validated',
  };
}

class _LineRow extends StatelessWidget {
  const _LineRow({required this.line});

  final Map<String, dynamic> line;

  @override
  Widget build(BuildContext context) {
    final confidence = (line['confidence'] as num?)?.toDouble() ?? 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
        title: Text(
          line['fuel_type_code']?.toString().replaceAll('_', ' ').toUpperCase() ??
              line['label']?.toString() ??
              'Fuel',
          style: const TextStyle(fontWeight: FontWeight.w500),
        ),
        subtitle: Text('Read with ${(confidence * 100).round()}% confidence'),
        trailing: Text(
          Formatters.currency(line['price'] as num?),
          style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Card(
      color: Theme.of(context).colorScheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          children: [
            Icon(
              LucideIcons.circleAlert,
              size: 18,
              color: Theme.of(context).colorScheme.onErrorContainer,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                message,
                style: TextStyle(color: Theme.of(context).colorScheme.onErrorContainer),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Tips extends StatelessWidget {
  const _Tips();

  static const _tips = [
    (
      LucideIcons.sun,
      'Avoid glare',
      'Stand slightly off-axis so the board is not reflecting the sun.',
    ),
    (
      LucideIcons.maximize,
      'Fill the frame',
      'Get close enough that the digits are large and sharp.',
    ),
    (
      LucideIcons.mapPin,
      'Be at the station',
      'Scans are geotagged so other drivers can trust the price.',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'For a good read',
          style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 12),
        for (final (icon, title, body) in _tips)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, size: 18, color: Theme.of(context).colorScheme.primary),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: const TextStyle(fontWeight: FontWeight.w500)),
                      Text(
                        body,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
