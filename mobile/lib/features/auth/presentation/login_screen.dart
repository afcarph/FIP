import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/network/api_exception.dart';
import '../../../shared/providers/app_providers.dart';
import '../../driver/data/build_identity.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _localAuth = LocalAuthentication();

  bool _submitting = false;
  bool _obscure = true;
  bool _biometricAvailable = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _checkBiometrics();
  }

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _checkBiometrics() async {
    try {
      final supported = await _localAuth.isDeviceSupported();
      final enrolled = await _localAuth.getAvailableBiometrics();

      // Only offer the shortcut when the device can actually do it *and* the
      // user has previously signed in on this install.
      final hasToken = await ref.read(apiClientProvider).readToken() != null;

      if (mounted) {
        setState(() => _biometricAvailable = supported && enrolled.isNotEmpty && hasToken);
      }
    } on Exception {
      // Biometric support is a bonus; failure here is not worth surfacing.
    }
  }

  Future<void> _signIn() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final api = ref.read(apiClientProvider);

      // Read the platform before the first await: touching `context` after one
      // is unsafe, since the widget may have been disposed by then.
      final platform = Theme.of(context).platform == TargetPlatform.iOS ? 'ios' : 'android';

      // Signing in registers the device too, and it was the path leaving rows
      // unnamed: only the driver setup flow ever sent a name, so every handset
      // whose owner had simply logged in appeared as a blank row in their own
      // device list. Best-effort — a null name never blocks a sign-in.
      final build = await BuildIdentity.resolve();

      final session = await api.post<Map<String, dynamic>>(
        '/auth/login',
        body: {
          'email': _emailController.text.trim(),
          'password': _passwordController.text,
          'device': {
            'device_uuid': await api.deviceUuid(),
            'platform': platform,
            if (build.deviceName != null) 'device_name': build.deviceName,
          },
        },
        skipAuth: true,
      );

      if (session['status'] == 'mfa_required') {
        if (mounted) {
          setState(
            () =>
                _error =
                    'This account requires a verification code. '
                    'Open the web app to complete two-factor sign-in.',
          );
        }
        return;
      }

      await ref.read(authProvider.notifier).completeSignIn(session);
    } on ApiException catch (error) {
      setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _signInWithBiometrics() async {
    try {
      final authenticated = await _localAuth.authenticate(
        localizedReason: 'Sign in to Fuel Intelligence Platform',
        options: const AuthenticationOptions(biometricOnly: true, stickyAuth: true),
      );

      if (!authenticated) return;

      // The stored token is restored rather than re-issued; the biometric
      // prompt gates local access to it.
      await ref.read(authProvider.notifier).restore();
    } on Exception catch (error) {
      setState(() => _error = 'Biometric sign-in failed: $error');
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // The actual FIP mark, not a generic fuel glyph. The
                    // artwork carries its own colour, so it sits on the
                    // surface rather than inside a tinted tile that would
                    // fight it.
                    Image.asset(
                      'assets/images/fip-mark.png',
                      width: 72,
                      height: 72,
                      semanticLabel: 'Fuel Intelligence Platform',
                    ),
                    const SizedBox(height: 20),
                    Text(
                      'Fuel Intelligence Platform',
                      textAlign: TextAlign.center,
                      style: Theme.of(
                        context,
                      ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 6),
                    // The product's own promise rather than a generic greeting.
                    // It is the first thing a new operator reads, and it sets
                    // the expectation that this is a fleet tool.
                    Text(
                      'See your fleet. Understand your fuel.\nAct with confidence.',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: scheme.onSurfaceVariant,
                        height: 1.45,
                      ),
                    ),
                    const SizedBox(height: 28),
                    if (_error != null) ...[
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: scheme.errorContainer,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(LucideIcons.circleAlert, size: 18, color: scheme.onErrorContainer),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                _error!,
                                style: TextStyle(color: scheme.onErrorContainer, fontSize: 13),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),
                    ],
                    TextFormField(
                      controller: _emailController,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.email],
                      decoration: const InputDecoration(
                        labelText: 'Email',
                        prefixIcon: Icon(LucideIcons.mail, size: 18),
                      ),
                      validator: (value) {
                        if (value == null || value.trim().isEmpty) {
                          return 'Enter your email address.';
                        }
                        if (!value.contains('@')) {
                          return 'That does not look like an email address.';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: 14),
                    TextFormField(
                      controller: _passwordController,
                      obscureText: _obscure,
                      textInputAction: TextInputAction.done,
                      autofillHints: const [AutofillHints.password],
                      onFieldSubmitted: (_) => _signIn(),
                      decoration: InputDecoration(
                        labelText: 'Password',
                        prefixIcon: const Icon(LucideIcons.lock, size: 18),
                        suffixIcon: IconButton(
                          icon: Icon(_obscure ? LucideIcons.eye : LucideIcons.eyeOff, size: 18),
                          onPressed: () => setState(() => _obscure = !_obscure),
                          tooltip: _obscure ? 'Show password' : 'Hide password',
                        ),
                      ),
                      validator:
                          (value) =>
                              (value == null || value.isEmpty) ? 'Enter your password.' : null,
                    ),
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: _submitting ? null : _signIn,
                      child:
                          _submitting
                              ? const SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                              : const Text('Sign in'),
                    ),
                    if (_biometricAvailable) ...[
                      const SizedBox(height: 12),
                      OutlinedButton.icon(
                        onPressed: _submitting ? null : _signInWithBiometrics,
                        icon: const Icon(LucideIcons.fingerprint, size: 18),
                        label: const Text('Use biometrics'),
                      ),
                    ],
                    const SizedBox(height: 24),
                    Text(
                      'Prices and forecasts are free to browse without an account.',
                      textAlign: TextAlign.center,
                      style: Theme.of(
                        context,
                      ).textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
