import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/app_colors.dart';
import '../core/backdrop.dart';
import '../core/glass.dart';
import '../core/motion.dart';

/// ============================================================
/// QUEUEFLOW TMC — SHARED PAGE FURNITURE
///
/// Every secondary screen is the same campus backdrop, a translucent
/// header with a back affordance, and a scrolling body. Building that
/// once keeps the new screens visually identical to the dashboard
/// instead of each inventing its own chrome.
/// ============================================================


/// Standard page shell: campus backdrop, header, scrollable body.
class QfPage extends StatelessWidget {
  const QfPage({
    super.key,
    required this.title,
    required this.children,
    this.subtitle,
    this.actions = const <Widget>[],
    this.onBack,
    this.padding = const EdgeInsets.fromLTRB(20, 4, 20, 32),
    this.floating,
    this.onRefresh,
  });

  final String title;
  final String? subtitle;
  final List<Widget> children;
  final List<Widget> actions;

  /// Supplied by pages that were pushed as a route, where it pops that
  /// route. Left null by shell tabs, where [QfHeader] decides what the
  /// leading button does instead.
  final VoidCallback? onBack;

  final EdgeInsetsGeometry padding;
  final Widget? floating;

  /// Turns the body into a pull-to-refresh list.
  ///
  /// Null on the pages whose content cannot go stale, where a refresh
  /// gesture would promise a round trip the screen has no use for. The body
  /// keeps its own scroll physics so the gesture still works when the page
  /// is already scrolled to the end - a list that only overscrolls at the
  /// top of its content cannot be pulled from where most readers are.
  final Future<void> Function()? onRefresh;

  @override
  Widget build(BuildContext context) {
    // Local so the null check promotes: the field itself cannot be promoted,
    // and the indicator wants a non-null callback.
    final Future<void> Function()? refresh = onRefresh;

    final Widget body = ListView(
      padding: padding,
      physics: refresh == null ? null : const AlwaysScrollableScrollPhysics(),
      children: children,
    );

    return Scaffold(
      backgroundColor: AppColors.primary,
      floatingActionButton: floating,
      body: CampusBackdrop(
        intensity: 0.72,
        child: SafeArea(
          bottom: false,
          child: Column(
            children: <Widget>[
              QfHeader(
                title: title,
                subtitle: subtitle,
                onBack: onBack,
                actions: actions,
              ),
              Expanded(
                child: refresh == null
                    ? body
                    : RefreshIndicator(
                        onRefresh: refresh,
                        color: AppColors.accent,
                        backgroundColor: AppColors.navy,
                        child: body,
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Makes the surrounding shell's navigation drawer and tab state reachable
/// from a page.
///
/// Rather than threading a callback through every page constructor,
/// [QfHeader] reads this scope to decide what its leading button does. A
/// shell tab has no route of its own to pop, so on the home tab the button
/// opens the drawer, and on every other tab it returns to the home tab.
///
/// Declared here rather than in `home_shell.dart` so page furniture can
/// depend on it without importing the shell, which imports the pages.
class QfDrawerScope extends InheritedWidget {
  const QfDrawerScope({
    super.key,
    required this.open,
    required this.goToTab,
    required this.currentTab,
    required this.tabLabels,
    required this.canSignOut,
    required this.onSignOut,
    required super.child,
  });

  /// Opens the shell's drawer.
  final VoidCallback open;

  /// Switches the shell to one of its tabs, for a shortcut inside a page.
  final void Function(int index) goToTab;

  final int currentTab;

  /// Tab labels, so a page can mark which one is showing.
  final List<String> tabLabels;

  /// False once the session is gone, which hides sign out.
  final bool canSignOut;

  final Future<void> Function() onSignOut;

  /// Null outside a shell, meaning "this page is a pushed route".
  static QfDrawerScope? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<QfDrawerScope>();

  @override
  bool updateShouldNotify(QfDrawerScope oldWidget) =>
      oldWidget.open != open ||
      oldWidget.goToTab != goToTab ||
      oldWidget.currentTab != currentTab ||
      oldWidget.canSignOut != canSignOut ||
      oldWidget.onSignOut != onSignOut;
}

/// Menu affordance that opens the enclosing shell's drawer.
class QfMenuButton extends StatelessWidget {
  const QfMenuButton({super.key, this.icon = Icons.menu_rounded});

  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final QfDrawerScope? scope = QfDrawerScope.maybeOf(context);

    return QfIconButton(icon: icon, tooltip: 'Menu', onTap: scope?.open);
  }
}

/// Translucent header shared by every page.
class QfHeader extends StatelessWidget {
  const QfHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.onBack,
    this.actions = const <Widget>[],
  });

  final String title;
  final String? subtitle;
  final VoidCallback? onBack;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final QfDrawerScope? scope = QfDrawerScope.maybeOf(context);

    // A shell tab is not a pushed route, so it has no route to pop. When the
    // tab is not the shell's home tab the arrow therefore goes back to that
    // home tab, which keeps every tab in the shell reachable both ways.
    final bool awayFromHome = scope != null && scope.currentTab != 0;
    final VoidCallback? leave =
        onBack ?? (awayFromHome ? () => scope.goToTab(0) : null);

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 16, 10),
      child: Row(
        children: <Widget>[
          if (leave != null) ...<Widget>[
            QfIconButton(
              icon: Icons.arrow_back_rounded,
              tooltip: 'Back',
              onTap: leave,
            ),
            const SizedBox(width: 6),
          ],
          if (scope != null) ...<Widget>[
            const QfMenuButton(),
            const SizedBox(width: 6),
          ] else if (leave == null)
            const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                if (subtitle != null)
                  Text(
                    subtitle!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.45),
                      fontSize: 11,
                    ),
                  ),
              ],
            ),
          ),
          ...actions,
        ],
      ),
    );
  }
}

/// Circular glass button used in headers.
class QfIconButton extends StatelessWidget {
  const QfIconButton({
    super.key,
    required this.icon,
    required this.tooltip,
    required this.onTap,
    this.size = 40,
  });

  final IconData icon;
  final String tooltip;

  /// Null renders the button dimmed and inert, which is what a screen
  /// needs while its own refresh is already running.
  final VoidCallback? onTap;
  final double size;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      pressedScale: 0.88,
      enabled: onTap != null,
      onTap: onTap,
      child: Tooltip(
        message: tooltip,
        child: AnimatedOpacity(
          duration: const Duration(milliseconds: 200),
          opacity: onTap == null ? 0.4 : 1,
          child: Container(
            width: size,
            height: size,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white.withValues(alpha: 0.08),
              border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
            ),
            child: Icon(
              icon,
              size: size * 0.47,
              color: Colors.white.withValues(alpha: 0.9),
            ),
          ),
        ),
      ),
    );
  }
}

/// Centred message used wherever a list can legitimately be empty.
class QfEmptyState extends StatelessWidget {
  const QfEmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.message,
    this.action,
  });

  final IconData icon;
  final String title;
  final String? message;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 24,
      child: Column(
        children: <Widget>[
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white.withValues(alpha: 0.07),
            ),
            child: Icon(icon, color: Colors.white54, size: 24),
          ),
          const SizedBox(height: 14),
          Text(
            title,
            textAlign: TextAlign.center,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 15.5,
              fontWeight: FontWeight.w700,
            ),
          ),
          if (message != null) ...<Widget>[
            const SizedBox(height: 5),
            Text(
              message!,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.55),
                fontSize: 12.5,
                height: 1.45,
              ),
            ),
          ],
          if (action != null) ...<Widget>[const SizedBox(height: 16), action!],
        ],
      ),
    );
  }
}

/// Label/value pair used on the profile, ticket and settings pages.
class QfInfoRow extends StatelessWidget {
  const QfInfoRow({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.valueColor,
  });

  final String label;
  final String value;
  final IconData? icon;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: 15, color: Colors.white.withValues(alpha: 0.45)),
            const SizedBox(width: 9),
          ],
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.55),
                fontSize: 13,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: TextStyle(
                color: valueColor ?? Colors.white,
                fontSize: 13,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Small rounded label, matching the dashboard's status pill.
class QfPill extends StatelessWidget {
  const QfPill({super.key, required this.text, this.color, this.icon});

  final String text;
  final Color? color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final Color tint = color ?? Colors.white;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tint.withValues(alpha: 0.35)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: 12, color: tint),
            const SizedBox(width: 5),
          ],
          Text(
            text,
            style: TextStyle(
              color: tint,
              fontSize: 10.5,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.6,
            ),
          ),
        ],
      ),
    );
  }
}

/// Themed text field, so forms across the app share one look.
class QfField extends StatelessWidget {
  const QfField({
    super.key,
    required this.controller,
    required this.label,
    this.hint,
    this.icon,
    this.keyboardType,
    this.obscure = false,
    this.maxLength,
    this.maxLines = 1,
    this.enabled = true,
    this.textCapitalization = TextCapitalization.none,
    this.errorText,
    this.onSubmitted,
    this.suffix,
    this.inputFormatters,
    this.autocorrect = true,
    this.centered = false,
    this.letterSpacing,
    this.fontSize = 14.5,
    this.focusNode,
  });

  final TextEditingController controller;
  final FocusNode? focusNode;
  final String label;
  final String? hint;
  final IconData? icon;
  final TextInputType? keyboardType;
  final bool obscure;
  final int? maxLength;
  final int maxLines;
  final bool enabled;
  final TextCapitalization textCapitalization;
  final String? errorText;
  final ValueChanged<String>? onSubmitted;

  /// Trailing affordance, used for the password visibility toggle.
  final Widget? suffix;

  /// Applied before the field is built, so a code field can reject
  /// non-digits and cap itself at six characters as the student types.
  final List<TextInputFormatter>? inputFormatters;

  final bool autocorrect;

  /// Centres the text, for one-time codes.
  final bool centered;

  /// Widened for spaced-out codes such as the reset code.
  final double? letterSpacing;

  final double fontSize;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.only(left: 4, bottom: 7),
          child: Text(
            label.toUpperCase(),
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.50),
              fontSize: 10,
              fontWeight: FontWeight.w800,
              letterSpacing: 1.3,
            ),
          ),
        ),
        TextField(
          controller: controller,
          focusNode: focusNode,
          obscureText: obscure,
          keyboardType: keyboardType,
          maxLength: maxLength,
          maxLines: obscure ? 1 : maxLines,
          enabled: enabled,
          autocorrect: autocorrect,
          enableSuggestions: autocorrect,
          inputFormatters: inputFormatters,
          textAlign: centered ? TextAlign.center : TextAlign.start,
          textCapitalization: textCapitalization,
          onSubmitted: onSubmitted,
          style: TextStyle(
            color: Colors.white,
            fontSize: fontSize,
            letterSpacing: letterSpacing,
            fontWeight: letterSpacing != null
                ? FontWeight.w700
                : FontWeight.w400,
          ),
          cursorColor: AppColors.accent,
          decoration: InputDecoration(
            hintText: hint,
            errorText: errorText,
            errorStyle: const TextStyle(
              color: AppColors.danger,
              fontSize: 11.5,
            ),
            prefixIcon: icon == null
                ? null
                : Icon(
                    icon,
                    size: 18,
                    color: Colors.white.withValues(alpha: 0.45),
                  ),
            suffixIcon: suffix,
            filled: true,
            fillColor: Colors.white.withValues(alpha: 0.07),
            counterText: '',
            contentPadding: const EdgeInsets.symmetric(
              horizontal: 16,
              vertical: 15,
            ),
            hintStyle: TextStyle(
              color: Colors.white.withValues(alpha: 0.30),
              fontSize: 14,
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(15),
              borderSide: BorderSide(
                color: Colors.white.withValues(alpha: 0.13),
              ),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(15),
              borderSide: const BorderSide(color: AppColors.accent, width: 1.4),
            ),
            errorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(15),
              borderSide: const BorderSide(color: AppColors.danger, width: 1.2),
            ),
            focusedErrorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(15),
              borderSide: const BorderSide(color: AppColors.danger, width: 1.4),
            ),
            disabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(15),
              borderSide: BorderSide(
                color: Colors.white.withValues(alpha: 0.06),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Inline banner for errors and confirmations inside a page.
class QfBanner extends StatelessWidget {
  const QfBanner({
    super.key,
    required this.message,
    this.icon = Icons.error_outline_rounded,
    this.tone = QfBannerTone.danger,
  });

  final String message;
  final IconData icon;
  final QfBannerTone tone;

  @override
  Widget build(BuildContext context) {
    final Color accent = switch (tone) {
      QfBannerTone.danger => AppColors.danger,
      QfBannerTone.success => AppColors.success,
      QfBannerTone.info => AppColors.accent,
    };

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.13),
        borderRadius: BorderRadius.circular(15),
        border: Border.all(color: accent.withValues(alpha: 0.34)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icon, size: 17, color: accent),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                color: accent,
                fontSize: 12.5,
                height: 1.45,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

enum QfBannerTone { danger, success, info }

/// Tappable glass row used by the office and menu pickers.
class QfSelectTile extends StatelessWidget {
  const QfSelectTile({
    super.key,
    required this.title,
    required this.onTap,
    this.subtitle,
    this.leading,
    this.trailing,
    this.selected = false,
    this.enabled = true,
  });

  final String title;
  final String? subtitle;
  final VoidCallback onTap;
  final Widget? leading;
  final Widget? trailing;
  final bool selected;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      pressedScale: 0.985,
      onTap: onTap,
      enabled: enabled,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 220),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(15),
        decoration: BoxDecoration(
          color: selected
              ? AppColors.accent.withValues(alpha: 0.13)
              : Colors.white.withValues(alpha: 0.06),
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: selected
                ? AppColors.accent.withValues(alpha: 0.45)
                : Colors.white.withValues(alpha: 0.10),
            width: selected ? 1.3 : 1,
          ),
        ),
        child: Row(
          children: <Widget>[
            if (leading != null) ...<Widget>[
              leading!,
              const SizedBox(width: 13),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    title,
                    style: TextStyle(
                      color: enabled ? Colors.white : Colors.white38,
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  if (subtitle != null) ...<Widget>[
                    const SizedBox(height: 3),
                    Text(
                      subtitle!,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.48),
                        fontSize: 11.5,
                        height: 1.35,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (trailing != null) ...<Widget>[
              const SizedBox(width: 10),
              trailing!,
            ] else if (selected)
              const Icon(
                Icons.check_circle_rounded,
                color: AppColors.accent,
                size: 20,
              ),
          ],
        ),
      ),
    );
  }
}
