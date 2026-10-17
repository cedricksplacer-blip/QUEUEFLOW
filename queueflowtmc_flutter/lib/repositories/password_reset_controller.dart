import 'package:flutter/foundation.dart';

import '../services/campus_backend.dart';
import '../services/campus_backend_factory.dart';

/// ============================================================
/// QUEUEFLOW TMC — PASSWORD RECOVERY
///
/// Deliberately separate from [AuthRepository]: nothing here runs while
/// signed in, and it must stay usable when the session is dead, which is
/// the usual reason a student opens it.
///
/// Holds the in-flight reset so the code screen survives a rebuild, and
/// nothing else — the code itself is never written to disk, so a stolen
/// device with the app open cannot read a live code back out of storage.
///
/// The student identifies with a Student ID or an email address. The server
/// resolves the account from it and always mails the address the admin saved
/// on that account — the typed value is never used as the destination, so a
/// Student ID still reaches the registered inbox.
///
/// Where the code comes from depends on the transport, and this is the one
/// place the two genuinely differ:
///
///   local campus  there is no mailbox, so the code is returned and shown
///                 on screen — it is the only way the reader learns it
///   live API      the code is emailed and never returned, so the screen
///                 says so rather than showing a number nobody issued
///
/// Both are time limited and rate limited per address, so neither can be
/// used to grind a password.
class PasswordResetController extends ChangeNotifier {
  PasswordResetController._();

  static final PasswordResetController instance = PasswordResetController._();

  CampusBackend get _backend => CampusBackendFactory.instance.current;

  /// Email address the code was requested for. Carried into the code step
  /// so the student does not have to retype it.
  String? _identifier;

  /// Server-issued proof the code step passed. Only ever set by [verifyCode];
  /// the new-password step refuses to run without it, so the screens cannot
  /// be deep-linked past verification. Held in memory only.
  String? _resetToken;

  /// The code that was issued, kept so the code step can show it
  /// prominently and offer to fill it in.
  ///
  /// Non-null only when the transport handed the code back. For the live API
  /// the code is emailed, so there is nothing to keep and nothing to show —
  /// which is why [hasIssuedCode] exists rather than a `null` check at each
  /// use. Held in memory only, exactly like [_identifier]: it is cleared when
  /// the flow is abandoned and never written to storage.
  String? _issuedCode;

  /// Email address pre-filled from the login form, before any code is requested.
  String? _seed;

  /// Human-readable status shown above the code field.
  String? _notice;

  /// True while a request or a reset is in flight, so both buttons can
  /// disable themselves.
  bool _busy = false;

  String? get identifier => _identifier;
  String? get notice => _notice;
  bool get isBusy => _busy;

  /// True once the server has verified the code, which is what unlocks the
  /// new-password screen.
  bool get isVerified => _resetToken != null && _resetToken!.isNotEmpty;

  /// The code issued for [_identifier], or null when no code is in flight.
  String? get issuedCode => _issuedCode;

  /// True when a code was returned to this device and can be shown or filled.
  ///
  /// False for a code that was mailed instead, which is what stops the screen
  /// offering to fill in a number the server never gave out.
  bool get hasIssuedCode => _issuedCode != null && _issuedCode!.isNotEmpty;

  /// True once a code has been sent, which is what switches the screen
  /// from the address form to the code form.
  bool get codeSent => _identifier != null;

  /// Asks the backend for a reset code.
  ///
  /// [identifier] is the student's Student ID or email address. Staff and
  /// administrators are not part of this build, so the screen collects one
  /// identifier shape and the server resolves exactly one account from it,
  /// then mails the registered address the admin saved on that account.
  ///
  /// Returns null on success, else the message to show.
  Future<String?> requestCode(String identifier) async {
    final String trimmed = identifier.trim();

    if (trimmed.isEmpty) {
      return 'Enter your Student ID or email address.';
    }

    // A typo'd identifier would wait on an inbox that never receives, so the
    // shape is checked before any request reaches the server (which checks
    // again, and answers generically either way so accounts cannot be
    // enumerated). A Student ID is digits with an optional dash; anything
    // else must look like an email address.
    if (!_looksLikeIdentifier(trimmed)) {
      return 'Enter a valid Student ID or email address.';
    }

    _busy = true;
    _notice = null;
    notifyListeners();

    try {
      final ResetCodeIssued result = await _backend.requestResetCode(trimmed);

      _identifier = trimmed;
      _issuedCode = result.code;

      _notice = result.isCoolingDown
          ? 'A code was requested very recently. Wait '
                '${result.retryAfterSeconds}s, then try again.'
          : result.hasCode
          // Only a transport that handed the code back may name it. Anything
          // else would be inventing a number the reader would wait for.
          ? '${result.message} Your code is ${result.code}, '
                'valid for ${result.expiresInMinutes} minutes.'
          : '${result.message} It is valid for '
                '${result.expiresInMinutes} minutes.';

      return null;
    } on CampusBackendException catch (error) {
      return error.message;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  /// Verifies [code] with the server. On success the server returns the
  /// single-use token that unlocks the new-password screen.
  ///
  /// Returns null on success, else the message to show. The code itself is
  /// consumed server-side, so a verified round cannot be replayed.
  Future<String?> verifyCode(String code) async {
    final String? identifier = _identifier;

    if (identifier == null) {
      return 'Request a reset code first.';
    }

    if (code.trim().isEmpty) {
      return 'Enter the 6-digit code.';
    }

    _busy = true;
    _notice = null;
    notifyListeners();

    try {
      _resetToken = await _backend.verifyCode(
        identifier: identifier,
        code: code.trim(),
      );

      _issuedCode = null;
      return null;
    } on CampusBackendException catch (error) {
      return error.message;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  /// Sets [password] using the verified token from [verifyCode].
  ///
  /// Refuses to run when the code step has not passed on the server, so the
  /// new-password screen cannot be reached by skipping ahead. Returns null
  /// on success, else the message. On success the flow is forgotten, which
  /// is why the screens pop back to the login form.
  Future<String?> submitNewPassword({
    required String password,
    required String passwordConfirmation,
  }) async {
    final String? identifier = _identifier;
    final String? token = _resetToken;

    if (identifier == null) {
      return 'Request a reset code first.';
    }

    if (token == null || token.isEmpty) {
      return 'Verify your code first.';
    }

    if (password != passwordConfirmation) {
      return 'The two passwords do not match.';
    }

    if (password.length < 8) {
      return 'Choose a password of at least 8 characters.';
    }

    _busy = true;
    _notice = null;
    notifyListeners();

    try {
      await _backend.resetPassword(
        identifier: identifier,
        resetToken: token,
        password: password,
      );

      _identifier = null;
      _issuedCode = null;
      _resetToken = null;
      return null;
    } on CampusBackendException catch (error) {
      return error.message;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  static bool _looksLikeEmail(String value) {
    return RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(value);
  }

  /// A Student ID is digits with an optional dash (e.g. `24-019083`).
  /// Mirrors the login form's normalisation: only the digit count matters,
  /// with the same minimum, so both screens accept the same spellings.
  static bool _looksLikeStudentId(String value) {
    final String digits = value.replaceAll(RegExp(r'[^0-9]'), '');

    if (digits.length < 6) {
      return false;
    }

    final String withoutSeparators = value.replaceAll(RegExp(r'[\s\-]'), '');

    return digits.length == withoutSeparators.length;
  }

  static bool _looksLikeIdentifier(String value) {
    return _looksLikeEmail(value) || _looksLikeStudentId(value);
  }

  /// Pre-fills the ID field from the login form.
  ///
  /// Kept apart from [_identifier] on purpose: [_identifier] is what signals
  /// that a code was actually issued, so reusing it here would drop the
  /// student straight onto the code step without a code ever having been
  /// issued.
  void seedIdentifier(String value) {
    _seed = value.trim();
    notifyListeners();
  }

  /// The ID the login form had typed, if any.
  String? get seed => _seed;

  /// Returns to the email step so a mistyped address can be corrected.
  /// A verified token does not survive this: going back means starting the
  /// round over with a fresh code.
  void backToIdentifier() {
    _identifier = null;
    _issuedCode = null;
    _resetToken = null;
    _notice = null;
    notifyListeners();
  }

  /// Pretends a code was already sent (and optionally verified), so the
  /// later steps can be exercised without a live mailbox.
  @visibleForTesting
  void debugSeed({String? identifier, String? notice, String? code, String? token}) {
    _identifier = identifier?.trim();
    _issuedCode = code;
    _resetToken = token;
    _seed = null;
    _notice = notice;
    notifyListeners();
  }

  /// Returns the controller to its untouched state for the next test.
  @visibleForTesting
  void debugReset() {
    _identifier = null;
    _issuedCode = null;
    _resetToken = null;
    _seed = null;
    _notice = null;
    _busy = false;
    notifyListeners();
  }
}
