import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/theme/fip_spacing.dart';
import '../../../shared/providers/app_providers.dart';
import '../../../shared/widgets/fip/fip_chrome.dart';
import '../../../shared/widgets/fip/section_header.dart';
import '../../driver/data/driver_providers.dart';
import '../../scanner/data/picker_failure.dart';
import '../../tracking/presentation/tracking_providers.dart';

/// Photograph a fuel receipt and let the server read it.
///
/// A client for `POST /expenses/scan-receipt`, which already existed and had
/// no caller — the driver's "Scan receipt" action was opening the price-board
/// scanner instead, which reads pump prices for other drivers rather than
/// recording a fill-up. Nothing here re-implements OCR: the image goes to the
/// same receipt pipeline the web client uses.
///
/// The vehicle is the driver's assigned one, sent so the server can sanity
/// check the reading against that vehicle. The endpoint authorises it; the app
/// does not decide.
class ReceiptScanScreen extends ConsumerStatefulWidget {
  const ReceiptScanScreen({super.key});

  @override
  ConsumerState<ReceiptScanScreen> createState() => _ReceiptScanScreenState();
}

class _ReceiptScanScreenState extends ConsumerState<ReceiptScanScreen> {
  final ImagePicker _picker = ImagePicker();

  File? _image;
  bool _scanning = false;
  Map<String, dynamic>? _result;
  String? _error;
  bool _errorIsPermission = false;

  Future<void> _capture(ImageSource source) async {
    try {
      final picked = await _picker.pickImage(
        source: source,
        // A receipt is text on paper: 2000px is plenty for OCR and a fraction
        // of the upload of a 12 MP original on mobile data.
        maxWidth: 2000,
        maxHeight: 2000,
        imageQuality: 88,
      );

      if (picked == null) return;

      setState(() {
        _image = File(picked.path);
        _result = null;
        _error = null;
        _errorIsPermission = false;
      });

      await _scan();
    } catch (error) {
      setState(() {
        _error = PickerFailure.describe(error);
        _errorIsPermission = PickerFailure.isPermissionDenial(error);
      });
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
      final vehicle = await ref.read(assignedVehicleProvider.future);

      final form = FormData.fromMap({
        'image': await MultipartFile.fromFile(image.path),
        if (vehicle != null) 'vehicle_id': vehicle.id,
      });

      final response = await ref
          .read(apiClientProvider)
          .upload<Map<String, dynamic>>('/expenses/scan-receipt', form);

      setState(() => _result = response);
    } on ApiException catch (error) {
      // The envelope's message, not the exception's toString — that would put
      // `ApiException(ocr_failed):` in front of a sentence written for a driver.
      setState(() => _error = error.message);
    } catch (error) {
      setState(() => _error = 'The receipt could not be read. Please try again.');
    } finally {
      if (mounted) setState(() => _scanning = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final vehicle = ref.watch(assignedVehicleProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Scan a receipt'),
        leading: const FipBackButton(fallback: '/home'),
      ),
      body: ListView(
        padding: const EdgeInsets.only(bottom: FipSpace.xxl),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(
              FipSpace.page,
              FipSpace.md,
              FipSpace.page,
              0,
            ),
            child: FipCard(
              padding: EdgeInsets.zero,
              child: AspectRatio(
                aspectRatio: 3 / 4,
                child: _image == null
                    ? Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(
                            Icons.receipt_long_rounded,
                            size: 34,
                            color: Theme.of(context).colorScheme.onSurfaceVariant,
                          ),
                          const SizedBox(height: FipSpace.sm),
                          Text('Photograph the receipt', style: FipType.caption(context)),
                        ],
                      )
                    : ClipRRect(
                        borderRadius: BorderRadius.circular(FipRadius.card),
                        child: Image.file(_image!, fit: BoxFit.cover),
                      ),
              ),
            ),
          ),

          Padding(
            padding: const EdgeInsets.fromLTRB(
              FipSpace.page,
              FipSpace.gap,
              FipSpace.page,
              0,
            ),
            child: Row(
              children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _scanning ? null : () => _capture(ImageSource.camera),
                    icon: const Icon(Icons.photo_camera_rounded, size: 18),
                    label: const Text('Take a photo'),
                  ),
                ),
                const SizedBox(width: FipSpace.gap),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _scanning ? null : () => _capture(ImageSource.gallery),
                    icon: const Icon(Icons.photo_library_rounded, size: 18),
                    label: const Text('Choose'),
                  ),
                ),
              ],
            ),
          ),

          if (_scanning)
            const Padding(
              padding: EdgeInsets.all(FipSpace.xl),
              child: Center(child: CircularProgressIndicator()),
            ),

          if (_error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                FipSpace.page,
                FipSpace.gap,
                FipSpace.page,
                0,
              ),
              child: FipCard(
                accent: Theme.of(context).colorScheme.error,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _error!,
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                    if (_errorIsPermission) ...[
                      const SizedBox(height: FipSpace.sm),
                      OutlinedButton(
                        onPressed: () => ref
                            .read(trackingControllerProvider.notifier)
                            .openSettings(ref.read(trackingControllerProvider)),
                        child: const Text('Open settings'),
                      ),
                    ],
                  ],
                ),
              ),
            ),

          if (_result != null) ...[
            const SectionHeader(title: 'What the receipt says', icon: Icons.summarize_rounded),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
              child: FipCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final entry in _result!.entries.take(8))
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 3),
                        child: Row(
                          children: [
                            Expanded(
                              child: Text(
                                entry.key.replaceAll('_', ' '),
                                style: FipType.caption(context),
                              ),
                            ),
                            Flexible(
                              child: Text(
                                '${entry.value}',
                                style: Theme.of(context).textTheme.labelLarge,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                      ),
                    const SizedBox(height: FipSpace.sm),
                    Text(
                      'Check these figures before recording the fill-up. OCR reads paper, and paper creases.',
                      style: FipType.caption(context),
                    ),
                  ],
                ),
              ),
            ),
          ],

          const SectionHeader(title: 'Vehicle', icon: Icons.local_shipping_rounded),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: FipSpace.page),
            child: FipCard(
              child: Text(
                vehicle.maybeWhen(
                  data: (v) => v == null
                      ? 'No vehicle assigned, so the receipt will be read without one.'
                      : 'The receipt will be checked against ${v.plateNumber}.',
                  orElse: () => 'Checking your assigned vehicle…',
                ),
                style: FipType.caption(context),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
