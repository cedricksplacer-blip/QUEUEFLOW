import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../models/student.dart';
import '../repositories/auth_repository.dart';
import '../widgets/connection_status.dart';
import '../widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — PROFILE
///
/// Read view by default, edit view on demand. The form starts from the
/// cached profile rather than an empty slate so a failed save does not
/// wipe what the student typed.
///
/// Course is deliberately not editable: it decides which offices and
/// Registrar counters the student may queue at, so only the registrar
/// can change it. Name and student number are likewise read-only: only
/// an administrator (web Admin Dashboard > User Management) may change
/// them, and the server ignores them on this endpoint.
/// ============================================================

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final AuthRepository _auth = AuthRepository.instance;

  late final TextEditingController _email;
  late final TextEditingController _block;
  late final TextEditingController _yearLevel;
  late final TextEditingController _phone;

  final TextEditingController _currentPassword = TextEditingController();
  final TextEditingController _newPassword = TextEditingController();
  final TextEditingController _confirmPassword = TextEditingController();

  bool _editing = false;
  bool _saving = false;
  bool _changingPassword = false;
  String? _error;
  String? _success;

  @override
  void initState() {
    super.initState();
    final Student? s = _auth.student;

    _email = TextEditingController(text: s?.email ?? '');
    _block = TextEditingController(text: s?.block ?? '');
    _yearLevel = TextEditingController(text: s?.yearLevel ?? '');
    _phone = TextEditingController(text: s?.phone ?? '');

    _auth.addListener(_onAuthChanged);
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    _email.dispose();
    _block.dispose();
    _yearLevel.dispose();
    _phone.dispose();
    _currentPassword.dispose();
    _newPassword.dispose();
    _confirmPassword.dispose();
    super.dispose();
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  void _resetForm() {
    final Student? s = _auth.student;

    setState(() {
      _email.text = s?.email ?? '';
      _block.text = s?.block ?? '';
      _yearLevel.text = s?.yearLevel ?? '';
      _phone.text = s?.phone ?? '';
    });
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();

    setState(() {
      _saving = true;
      _error = null;
      _success = null;
    });

    final String? failure = await _auth.updateProfile(
      email: _email.text.trim(),
      block: _block.text.trim(),
      yearLevel: _yearLevel.text.trim(),
      phone: _phone.text.trim(),
    );

    if (!mounted) {
      return;
    }

    setState(() {
      _saving = false;

      if (failure == null) {
        _editing = false;
        _success = 'Profile saved.';
        _resetForm();
      } else {
        _error = failure;
      }
    });
  }

  Future<void> _changePassword() async {
    FocusScope.of(context).unfocus();

    setState(() {
      _changingPassword = true;
      _error = null;
      _success = null;
    });

    final String? failure = await _auth.changePassword(
      currentPassword: _currentPassword.text,
      password: _newPassword.text,
      passwordConfirmation: _confirmPassword.text,
    );

    if (!mounted) {
      return;
    }

    if (failure != null) {
      setState(() {
        _changingPassword = false;
        _error = failure;
      });
      return;
    }

    // On success the server revoked every token, so the app is already
    // back at the sign-in gate. Only the fields need clearing here.
    setState(() => _changingPassword = false);
    _currentPassword.clear();
    _newPassword.clear();
    _confirmPassword.clear();
  }

  @override
  Widget build(BuildContext context) {
    final Student? student = _auth.student;

    if (student == null) {
      return const QfPage(
        title: 'Profile',
        subtitle: '',
        children: <Widget>[
          QfEmptyState(
            icon: Icons.person_off_outlined,
            title: 'Not signed in',
            message: 'Sign in to view your profile.',
          ),
        ],
      );
    }

    return QfPage(
      title: 'Profile',
      subtitle: student.academicSummary,
      actions: <Widget>[
        QfIconButton(
          icon: _editing ? Icons.close_rounded : Icons.edit_outlined,
          tooltip: _editing ? 'Cancel editing' : 'Edit profile',
          onTap: () {
            if (_editing) {
              _resetForm();
            }

            setState(() {
              _editing = !_editing;
              _error = null;
              _success = null;
            });
          },
        ),
      ],
      children: <Widget>[
        if (_error != null) ...<Widget>[
          QfBanner(message: _error!),
          const SizedBox(height: 16),
        ],
        if (_success != null) ...<Widget>[
          QfBanner(
            message: _success!,
            icon: Icons.check_circle_outline_rounded,
            tone: QfBannerTone.success,
          ),
          const SizedBox(height: 16),
        ],

        _IdentityCard(student: student),

        const SizedBox(height: 22),
        if (_editing) ...<Widget>[
          const SectionTitle(text: 'Edit details'),
          // Name and student number are read-only: only an administrator
          // (web Admin Dashboard > User Management) may change them. They
          // are shown here so the student can verify them, never typed.
          GlassCard(
            radius: 22,
            child: Column(
              children: <Widget>[
                QfInfoRow(
                  label: 'Full name',
                  value: student.name,
                  icon: Icons.badge_outlined,
                ),
                QfInfoRow(
                  label: 'Student number',
                  value: student.studentId ?? 'Not set',
                  icon: Icons.numbers_rounded,
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          const QfBanner(
            message:
                'Your name and student number are managed by the administrator. '
                'Contact the registrar if either is incorrect.',
            icon: Icons.lock_outline_rounded,
            tone: QfBannerTone.info,
          ),
          const SizedBox(height: 12),
          QfField(
            controller: _email,
            label: 'Email address',
            icon: Icons.alternate_email_rounded,
            keyboardType: TextInputType.emailAddress,
            enabled: !_saving,
            autocorrect: false,
          ),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              Expanded(
                child: QfField(
                  controller: _yearLevel,
                  label: 'Year level',
                  icon: Icons.school_outlined,
                  enabled: !_saving,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: QfField(
                  controller: _block,
                  label: 'Block',
                  icon: Icons.grid_view_rounded,
                  enabled: !_saving,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          QfField(
            controller: _phone,
            label: 'Contact number',
            icon: Icons.phone_outlined,
            keyboardType: TextInputType.phone,
            enabled: !_saving,
          ),
          const SizedBox(height: 22),
          // The profile lives on the server; a local edit would be lost on the
          // next successful read. Disabled rather than hidden while offline,
          // with the reason shown, so the reader knows it is the connection
          // and not a broken form.
          ServerActionButton(
            child: PrimaryButton(
              label: 'Save changes',
              icon: Icons.save_rounded,
              busy: _saving,
              onPressed: _saving ? null : _save,
            ),
          ),
          const SizedBox(height: 10),
          Center(
            child: TextButton(
              onPressed: _saving
                  ? null
                  : () {
                      _resetForm();
                      setState(() => _editing = false);
                    },
              child: Text(
                'Discard',
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.60),
                  fontSize: 12.5,
                ),
              ),
            ),
          ),
        ] else ...<Widget>[
          const SectionTitle(text: 'Details'),
          GlassCard(
            radius: 22,
            child: Column(
              children: <Widget>[
                QfInfoRow(
                  label: 'Full name',
                  value: student.name,
                  icon: Icons.badge_outlined,
                ),
                QfInfoRow(
                  label: 'Email',
                  value: student.email,
                  icon: Icons.alternate_email_rounded,
                ),
                QfInfoRow(
                  label: 'Student number',
                  value: student.studentId ?? 'Not set',
                  icon: Icons.numbers_rounded,
                ),
                QfInfoRow(
                  label: 'Course',
                  value: student.course ?? 'Not assigned',
                  icon: Icons.school_outlined,
                ),
                QfInfoRow(
                  label: 'Year level',
                  value: student.yearLevel ?? 'Not set',
                  icon: Icons.workspace_premium_outlined,
                ),
                QfInfoRow(
                  label: 'Block',
                  value: student.block ?? 'Not set',
                  icon: Icons.grid_view_rounded,
                ),
                QfInfoRow(
                  label: 'Contact',
                  value: student.phone ?? 'Not set',
                  icon: Icons.phone_outlined,
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          const QfBanner(
            message:
                'Your name, student number, course and year level are set by '
                'the administrator and registrar, because they decide who you '
                'are and which offices you can queue at.',
            icon: Icons.lock_outline_rounded,
            tone: QfBannerTone.info,
          ),
        ],

        const SizedBox(height: 24),
        const SectionTitle(text: 'Change password'),
        GlassCard(
          radius: 22,
          child: Column(
            children: <Widget>[
              QfField(
                controller: _currentPassword,
                label: 'Current password',
                icon: Icons.lock_outline_rounded,
                obscure: true,
                enabled: !_changingPassword,
              ),
              const SizedBox(height: 12),
              QfField(
                controller: _newPassword,
                label: 'New password',
                hint: 'At least 8 characters',
                icon: Icons.lock_reset_rounded,
                obscure: true,
                enabled: !_changingPassword,
              ),
              const SizedBox(height: 12),
              QfField(
                controller: _confirmPassword,
                label: 'Confirm new password',
                icon: Icons.verified_user_outlined,
                obscure: true,
                enabled: !_changingPassword,
              ),
              const SizedBox(height: 16),
              PrimaryButton(
                label: 'Update password',
                icon: Icons.key_rounded,
                busy: _changingPassword,
                height: 50,
                onPressed: _changingPassword ? null : _changePassword,
              ),
              const SizedBox(height: 10),
              Text(
                'Changing your password signs this device out.',
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.38),
                  fontSize: 11,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Avatar, name, and the account's current standing.
class _IdentityCard extends StatelessWidget {
  const _IdentityCard({required this.student});

  final Student student;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 26,
      glow: AppColors.violet,
      padding: const EdgeInsets.all(20),
      child: Row(
        children: <Widget>[
          Container(
            width: 62,
            height: 62,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: AppColors.brandGradient,
              boxShadow: <BoxShadow>[
                BoxShadow(
                  color: AppColors.violet.withValues(alpha: 0.35),
                  blurRadius: 18,
                ),
              ],
            ),
            alignment: Alignment.center,
            child: Text(
              student.initials,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 22,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  student.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    height: 1.25,
                  ),
                ),
                const SizedBox(height: 5),
                Text(
                  student.academicSummary,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.55),
                    fontSize: 12,
                  ),
                ),
                const SizedBox(height: 8),
                QfPill(
                  text: student.isActive ? 'Active' : 'Inactive',
                  color: student.isActive
                      ? AppColors.success
                      : AppColors.danger,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
