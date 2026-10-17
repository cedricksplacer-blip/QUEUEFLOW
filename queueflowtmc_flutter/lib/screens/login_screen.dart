import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/app_colors.dart';
import '../core/app_constants.dart';
import '../core/backdrop.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../core/network/connectivity_service.dart';
import '../repositories/auth_repository.dart';
import '../widgets/connection_status.dart';
import 'forgot_password_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — LOGIN
///
/// A frosted form floating over the campus photograph: logo, wordmark, ID
/// Number and Password.
///
/// There is no sign-up here, and no way to reach one. Accounts are issued by
/// the registrar, so the only two questions this screen asks are which ID is
/// yours and what your password is. A self-service registration form on a
/// student portal is an open door into somebody else's record, and there is no
/// version of it that is safe enough to be worth the convenience.
///
/// There is deliberately no role picker either. The server decides what the
/// account is; this form only knows how to send an ID and read a refusal.
///
/// CONNECTION IS CHECKED BEFORE THE CREDENTIALS ARE SENT
///
/// [ConnectionBanner] is always in the tree, so the reader can see the state
/// of the network before typing anything, and [_submit] confirms it before
/// calling the API. That ordering is deliberate. Posting credentials to a
/// server that is not there produces a generic connection error after a full
/// timeout, which reads as "wrong password" to somebody in a hurry; checking
/// first produces the accurate message immediately - "no internet" versus
/// "the QueueFlow server is offline" - and those need different actions.
///
/// The check is a real HTTP probe, not a Wi-Fi icon. The two disagree exactly
/// when it matters: Wi-Fi up with `artisan serve` stopped is the most common
/// failure on a campus network, and it is invisible to the operating system.
/// ============================================================

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  final TextEditingController _identifierController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();

  final AuthRepository _auth = AuthRepository.instance;

  /// The one source of truth for whether the server is reachable.
  ///
  /// The process-wide instance rather than a per-screen one: a second instance
  /// would run its own probe and could disagree with the banner, the shells and
  /// every other caller about whether the server is up.
  final ConnectivityService _connectivity = ConnectivityService.instance;

  /// The fewest digits an ID Number may have and still be worth sending.
  ///
  /// Deliberately loose. The length of a college ID is the registrar's business,
  /// so the form does not second-guess it: what this catches is a stray
  /// character or a half-typed ID, not a shape it cannot verify.
  static const int _idDigitsMin = 6;

  /// Strips everything but digits, so a dash and a missing dash are the same
  /// ID. Mirrors the normalisation the campus compares on.
  static String _digitsOf(String value) =>
      value.replaceAll(RegExp(r'[^0-9]'), '');

  bool _obscure = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    _identifierController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _onAuthChanged() {
    if (!mounted) {
      return;
    }

    setState(() {
      _busy = _auth.status == AuthStatus.busy;
      _error = _auth.error;
    });
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();

    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    // Confirm the server is actually there before spending the credentials.
    //
    // `refresh()` rather than reading the current state: a reader who has just
    // opened the app on a phone that was in a lift a moment ago has a stale
    // snapshot, and failing them on it would be wrong in the other direction.
    // The probe is awaited, so the message that follows describes a real
    // answer, and it cannot freeze the UI - the button is already in its busy
    // state and the check is bounded by the request timeout.
    final AppConnectionState state = await _connectivity.refresh();

    if (state != AppConnectionState.online) {
      if (!mounted) {
        return;
      }

      // The connectivity service owns this wording so the banner, the status
      // pill and this error can never disagree about what is wrong.
      setState(() {
        _busy = false;
        _error = _connectivity.message.body;
      });

      return;
    }

    final bool ok = await _auth.signIn(
      identifier: _identifierController.text.trim(),
      password: _passwordController.text,
    );

    if (!mounted) {
      return;
    }

    setState(() {
      _busy = false;
      _error = ok ? null : _auth.error;
    });

    if (ok) {
      _auth.clearError();
    }
  }

  /// Opens password recovery, which is email-only: the Student ID typed on
  /// the login form cannot be used there, so nothing is carried over.
  Future<void> _openForgotPassword() async {
    FocusScope.of(context).unfocus();

    await Navigator.of(
      context,
    ).push<void>(QfPageRoute<void>(page: const ForgotPasswordScreen()));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.primary,
      body: CampusBackdrop(
        intensity: 0.95,
        child: SafeArea(
          child: LayoutBuilder(
            builder: (BuildContext context, BoxConstraints constraints) {
              return SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(22, 20, 22, 28),
                child: ConstrainedBox(
                  constraints: BoxConstraints(
                    minHeight: constraints.maxHeight - 48,
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      const SizedBox(height: 16),

                      // Logo pops in with a back-out spring.
                      RevealIn(
                        duration: const Duration(milliseconds: 1000),
                        curve: QfCurves.spring,
                        scale: 0.55,
                        offset: Offset.zero,
                        child: const Center(child: CampusLogo(size: 116)),
                      ),

                      const SizedBox(height: 42),

                      // Always in the tree, collapses to nothing while the
                      // connection is healthy. Placing it above the form means
                      // a reader with no connection is told before they start
                      // typing, rather than after.
                      ConnectionBanner(service: _connectivity),

                      RevealIn(
                        delay: const Duration(milliseconds: 220),
                        child: Center(
                          child: GradientText(
                            text: AppConstants.appName,
                            colors: const <Color>[
                              Colors.white,
                              AppColors.surfaceTint,
                              Colors.white,
                            ],
                            style: const TextStyle(
                              fontSize: 30,
                              fontWeight: FontWeight.w800,
                              letterSpacing: 1.2,
                            ),
                          ),
                        ),
                      ),

                      const SizedBox(height: 8),

                      RevealIn(
                        delay: const Duration(milliseconds: 340),
                        child: Text(
                          AppConstants.appTagline,
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: Colors.white.withValues(alpha: 0.62),
                            fontSize: 13,
                            letterSpacing: 1.6,
                          ),
                        ),
                      ),

                      const SizedBox(height: 30),

                      RevealIn(
                        delay: const Duration(milliseconds: 460),
                        child: GlassCard(
                          padding: const EdgeInsets.all(24),
                          radius: 30,
                          glow: AppColors.primary,
                          child: Form(
                            key: _formKey,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: <Widget>[
                                const Text(
                                  'Welcome back',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 23,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                const SizedBox(height: 5),
                                // No role picker and no sign-up link: accounts
                                // are issued by the registrar, so this screen
                                // only ever asks who you are.
                                Text(
                                  'Sign in with your ${AppConstants.schoolName} '
                                  'account.',
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: 0.60),
                                    fontSize: 13.5,
                                  ),
                                ),
                                const SizedBox(height: 22),

                                if (_error != null) ...<Widget>[
                                  _ErrorBanner(message: _error!),
                                  const SizedBox(height: 18),
                                ],

                                TextFormField(
                                  controller: _identifierController,
                                  enabled: !_busy,
                                  keyboardType: TextInputType.number,
                                  textInputAction: TextInputAction.next,
                                  autocorrect: false,
                                  enableSuggestions: false,
                                  // Digits and the single dash the registrar
                                  // prints. Anything else is a typo, and
                                  // catching it here saves a round trip.
                                  inputFormatters: <TextInputFormatter>[
                                    FilteringTextInputFormatter.allow(
                                      RegExp(r'[0-9\-]'),
                                    ),
                                  ],
                                  decoration: const InputDecoration(
                                    labelText: 'ID Number',
                                    hintText: '24-019083',
                                    prefixIcon: Icon(Icons.badge_outlined),
                                  ),
                                  validator: (String? value) {
                                    final String typed = (value ?? '').trim();

                                    if (typed.isEmpty) {
                                      return 'Enter your ID Number.';
                                    }

                                    final String digits = _digitsOf(typed);

                                    // Only the dash and whitespace are forgiven,
                                    // because they are the two ways one ID gets
                                    // typed two ways.
                                    final String withoutSeparators = typed
                                        .replaceAll(RegExp(r'[\s\-]'), '');

                                    if (digits.length < _idDigitsMin ||
                                        digits.length !=
                                            withoutSeparators.length) {
                                      return 'An ID Number is digits, with an '
                                          'optional dash.';
                                    }

                                    return null;
                                  },
                                ),
                                const SizedBox(height: 14),

                                TextFormField(
                                  controller: _passwordController,
                                  enabled: !_busy,
                                  obscureText: _obscure,
                                  // A password must reach the server exactly
                                  // as typed: the backend compares it byte
                                  // for byte, so autocorrect, suggestions and
                                  // auto-capitalisation would turn a correct
                                  // password into a 401 on some attempts.
                                  keyboardType:
                                      TextInputType.visiblePassword,
                                  textCapitalization:
                                      TextCapitalization.none,
                                  autocorrect: false,
                                  enableSuggestions: false,
                                  textInputAction: TextInputAction.done,
                                  decoration: InputDecoration(
                                    labelText: 'Password',
                                    prefixIcon: const Icon(
                                      Icons.lock_outline_rounded,
                                    ),
                                    suffixIcon: IconButton(
                                      onPressed: () {
                                        setState(() {
                                          _obscure = !_obscure;
                                        });
                                      },
                                      tooltip: _obscure
                                          ? 'Show password'
                                          : 'Hide password',
                                      icon: Icon(
                                        _obscure
                                            ? Icons.visibility_off_outlined
                                            : Icons.visibility_outlined,
                                      ),
                                    ),
                                  ),
                                  onFieldSubmitted: (_) {
                                    if (!_busy) {
                                      _submit();
                                    }
                                  },
                                  validator: (String? value) {
                                    if ((value ?? '').isEmpty) {
                                      return 'Please enter your password.';
                                    }

                                    return null;
                                  },
                                ),
                                const SizedBox(height: 24),

                                PrimaryButton(
                                  label: 'LOGIN',
                                  icon: Icons.arrow_forward_rounded,
                                  busy: _busy,
                                  onPressed: _submit,
                                ),
                                const SizedBox(height: 6),

                                Center(
                                  child: TextButton(
                                    onPressed: _busy
                                        ? null
                                        : _openForgotPassword,
                                    child: Text(
                                      'Forgot Password',
                                      style: TextStyle(
                                        color: Colors.white.withValues(
                                          alpha: 0.62,
                                        ),
                                        fontSize: 12.5,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),

                      const SizedBox(height: 24),

                      RevealIn(
                        delay: const Duration(milliseconds: 680),
                        child: Column(
                          children: <Widget>[
                            Text(
                              AppConstants.campusLocation,
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: Colors.white.withValues(alpha: 0.45),
                                fontSize: 11.5,
                                height: 1.6,
                              ),
                            ),
                            const SizedBox(height: 10),
                            Text(
                              '© ${AppConstants.appName}',
                              style: TextStyle(
                                color: Colors.white.withValues(alpha: 0.30),
                                fontSize: 11,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

/// Inline error panel that slides into view when the server complains.
class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return RevealIn(
      duration: const Duration(milliseconds: 420),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.danger.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppColors.danger.withValues(alpha: 0.45)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Icon(
              Icons.error_outline_rounded,
              color: AppColors.danger,
              size: 19,
            ),
            const SizedBox(width: 11),
            Expanded(
              child: Text(
                message,
                style: const TextStyle(
                  color: Color(0xFFFFE2E4),
                  fontSize: 12.5,
                  height: 1.45,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
