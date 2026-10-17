import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';

import '../core/app_colors.dart';
import '../core/app_constants.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../models/queue_ticket.dart';
import '../models/student.dart';
import '../widgets/qf_page.dart';
import 'my_ticket_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — DIGITAL TICKET RECEIPT
///
/// The paper receipt handed to a student the moment their number is issued,
/// and again later from "My Ticket" and "History". It leads with the queue
/// number the server assigned - large, so it reads at a glance - and then
/// states the campus office, the purpose of visit, the priority and the date &
/// time it was generated, followed by the student's own identity and academic
/// details. Every value comes from the ticket the server issued or the
/// signed-in student's Laravel profile, never a guess.
///
/// The actions a student can take on it:
///
///   View My Ticket      - the live polling screen for this ticket
///   Share Receipt       - Android share sheet; every other platform copies
///   Save Receipt        - renders the receipt paper to a PNG in Documents
///   Copy details        - the same content as plain text on the clipboard
///   Back to Dashboard   - returns to the shell home
///
/// Saving renders the paper through a RepaintBoundary, so the image saved is
/// byte-for-byte what the student sees rather than a re-layout of the same
/// data.
/// ============================================================

class TicketReceiptScreen extends StatefulWidget {
  const TicketReceiptScreen({
    super.key,
    required this.ticket,
    this.progress,
    this.message,
    this.student,
  });

  /// The row the server issued. Authoritative for the number, the office,
  /// the service, the batch and the timestamps.
  final QueueTicket ticket;

  /// The queue position at the moment this receipt was built.
  ///
  /// Retained for the entry points that already pass it; the simplified
  /// receipt no longer renders the position.
  final QueueProgress? progress;

  /// The sentence the server wrote when the ticket was issued, e.g.
  /// "Queue S-001 generated successfully."
  ///
  /// Retained for the entry points that already pass it; the simplified
  /// receipt no longer renders it.
  final String? message;

  /// The signed-in student, used for the ID, name, course and year level on
  /// the receipt. The account this comes from was returned by Laravel, so
  /// the "database values" shown here are the server's own.
  final Student? student;

  @override
  State<TicketReceiptScreen> createState() => _TicketReceiptScreenState();
}

class _TicketReceiptScreenState extends State<TicketReceiptScreen> {
  static const MethodChannel _shareChannel = MethodChannel('qflow/receipt');

  /// Wraps the receipt paper so "Save image" can rasterise exactly what the
  /// student is looking at.
  final GlobalKey _receiptKey = GlobalKey();

  bool _saving = false;
  bool _sharing = false;

  QueueTicket get _ticket => widget.ticket;

  // ==========================================================
  // ACTIONS
  // ==========================================================

  void _openStatus() {
    Navigator.of(context).push<void>(
      QfPageRoute<void>(page: const MyTicketScreen()),
    );
  }

  void _backToDashboard() {
    Navigator.of(context).popUntil((Route<void> route) => route.isFirst);
  }

  Future<void> _share() async {
    final String text = _receiptText();
    final String ticketNo = _ticket.queueNumber;
    final String subject = '$AppConstants.appName — Ticket $ticketNo';

    // Android has a real share sheet wired through MainActivity. Every other
    // platform this build runs on gets the next-best thing: the same text on
    // the clipboard, so the student can paste it anywhere they were going to
    // share to.
    if (kIsWeb || !Platform.isAndroid) {
      await _copy(text);
      _toast('Receipt copied to the clipboard — paste it anywhere.');
      return;
    }

    setState(() => _sharing = true);

    try {
      await _shareChannel.invokeMethod<void>('shareText', <String, dynamic>{
        'subject': subject,
        'text': text,
      });
    } on MissingPluginException {
      if (mounted) {
        await _copy(text);
        _toast('Sharing is not available here — receipt copied instead.');
      }
    } on PlatformException {
      if (mounted) {
        await _copy(text);
        _toast('Sharing failed — receipt copied instead.');
      }
    } finally {
      if (mounted) {
        setState(() => _sharing = false);
      }
    }
  }

  Future<void> _save() async {
    setState(() => _saving = true);

    try {
      final String? path = await _renderToPng();

      if (!mounted) {
        return;
      }

      if (path == null) {
        _toast('Could not save the receipt right now.');
        return;
      }

      _toast('Receipt saved to: $path');
    } catch (_) {
      if (mounted) {
        _toast('Could not save the receipt right now.');
      }
    } finally {
      if (mounted) {
        setState(() => _saving = false);
      }
    }
  }

  Future<void> _copyReceipt() async {
    await _copy(_receiptText());
    _toast('Receipt details copied.');
  }

  Future<void> _copy(String text) => Clipboard.setData(ClipboardData(text: text));

  void _toast(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: AppColors.navy,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    );
  }

  // ==========================================================
  // RENDERING THE RECEIPT TO A FILE
  // ==========================================================

  /// Rasterises the on-screen receipt paper to a PNG under the app's
  /// documents directory, returning the file path.
  Future<String?> _renderToPng() async {
    final RenderObject? render = _receiptKey.currentContext?.findRenderObject();

    if (render is! RenderRepaintBoundary) {
      return null;
    }

    final ui.Image image = await render.toImage(pixelRatio: 2.5);
    final ByteData? bytes = await image.toByteData(
      format: ui.ImageByteFormat.png,
    );
    image.dispose();

    if (bytes == null) {
      return null;
    }

    final Directory documents = await getApplicationDocumentsDirectory();
    final Directory folder = Directory(
      '${documents.path}${Platform.pathSeparator}receipts',
    );

    if (!await folder.exists()) {
      await folder.create(recursive: true);
    }

    final String safeNumber = _ticket.queueNumber.replaceAll(
      RegExp(r'[^A-Za-z0-9-]'),
      '_',
    );
    final File file = File(
      '${folder.path}${Platform.pathSeparator}${safeNumber}_${_ticket.id}.png',
    );

    await file.writeAsBytes(bytes.buffer.asUint8List());

    return file.path;
  }

  /// The receipt as plain text, for the clipboard and the share sheet. It
  /// mirrors the paper: the brand, the queue number the server assigned, the
  /// ticket's own details and the student's identity.
  String _receiptText() {
    final List<String> lines = <String>[
      AppConstants.appName,
      'Official Queue Receipt',
      '',
      'Queue Number: ${_ticket.queueNumber}',
      'Status: ${_ticket.displayStatus}',
      'Campus Office: ${_ticket.officeName}',
      'Purpose of Visit: $_purpose',
      'Priority: ${_ticket.isPwd ? 'PWD' : 'Regular'}',
      'Date & Time: ${_formatFull(_ticket.createdAt)}',
    ];

    final String reason = (_ticket.cancelReason ?? '').trim();

    if (_ticket.isCancelled) {
      lines.add(
        'Cancellation Reason: ${reason.isEmpty ? 'No reason was provided.' : reason}',
      );
    }

    lines.addAll(<String>[
      '',
      'Student: $_name',
      'Student ID: $_studentId',
      'Course: $_course',
      'Year Level: $_yearLevel',
    ]);

    return lines.join('\n');
  }

  // ==========================================================
  // DERIVED DISPLAY VALUES
  // ==========================================================

  String get _name {
    final String? local = widget.student?.name;

    return (local == null || local.isEmpty) ? '—' : local;
  }

  String get _studentId {
    final String? id = widget.student?.studentId;

    return (id == null || id.isEmpty) ? '—' : id;
  }

  String get _course {
    final String? fromTicket = _ticket.course?.name;

    if (fromTicket != null && fromTicket.isNotEmpty) {
      return fromTicket;
    }

    if (_ticket.office?.code != null &&
        _ticket.office!.code.startsWith('REG-')) {
      final String? profile = widget.student?.course;

      if (profile != null && profile.isNotEmpty) {
        return profile;
      }
    }

    return '—';
  }

  String get _yearLevel {
    final String? fromTicket = _ticket.yearLevel;

    if (fromTicket != null && fromTicket.isNotEmpty) {
      return fromTicket;
    }

    final String? profile = widget.student?.yearLevel;

    return (profile == null || profile.isEmpty) ? '—' : profile;
  }

  /// The purpose of visit filed with the ticket, or an em dash when the
  /// server stored none.
  String get _purpose {
    final String? purpose = _ticket.purpose?.trim();

    return (purpose == null || purpose.isEmpty) ? '—' : purpose;
  }

  // ==========================================================
  // BUILD
  // ==========================================================

  @override
  Widget build(BuildContext context) {
    return QfPage(
      title: AppConstants.appName,
      subtitle: 'Ticket Receipt',
      onBack: () => Navigator.of(context).maybePop(),
      children: <Widget>[
        // The document itself. Wrapped in the RepaintBoundary "Save image"
        // rasterises, so what is saved is what is shown.
        RepaintBoundary(
          key: _receiptKey,
          child: _ReceiptPaper(
            ticket: _ticket,
            purpose: _purpose,
            studentName: _name,
            studentId: _studentId,
            course: _course,
            yearLevel: _yearLevel,
          ),
        ),
        const SizedBox(height: 22),
        const SectionTitle(text: 'Actions'),
        const SizedBox(height: 10),
        PrimaryButton(
          label: 'View My Ticket',
          icon: Icons.bolt_rounded,
          onPressed: _saving || _sharing ? null : _openStatus,
        ),
        const SizedBox(height: 12),
        Row(
          children: <Widget>[
            Expanded(
              child: _GhostAction(
                label: _sharing ? 'Sharing...' : 'Share Receipt',
                icon: Icons.ios_share_rounded,
                busy: _sharing,
                onPressed: _saving || _sharing ? null : _share,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _GhostAction(
                label: _saving ? 'Saving...' : 'Save Receipt',
                icon: Icons.download_rounded,
                busy: _saving,
                onPressed: _saving || _sharing ? null : _save,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        _GhostAction(
          label: 'Copy receipt details',
          icon: Icons.content_copy_rounded,
          onPressed: _saving || _sharing ? null : _copyReceipt,
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _backToDashboard,
          child: const Text(
            'Back to Dashboard',
            style: TextStyle(color: Colors.white70, fontSize: 13),
          ),
        ),
      ],
    );
  }
}

/// The white receipt "paper" carrying the branded header, the queue number the
/// server assigned (large), the ticket's own details - campus office, purpose
/// of visit, priority and the date & time it was generated - and the student's
/// identity. Every value comes from the server-issued ticket or the signed-in
/// student's profile.
class _ReceiptPaper extends StatelessWidget {
  const _ReceiptPaper({
    required this.ticket,
    required this.purpose,
    required this.studentName,
    required this.studentId,
    required this.course,
    required this.yearLevel,
  });

  final QueueTicket ticket;
  final String purpose;
  final String studentName;
  final String studentId;
  final String course;
  final String yearLevel;

  @override
  Widget build(BuildContext context) {
    return GlassCard.sheet(
      radius: 28,
      padding: const EdgeInsets.all(22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: Image.asset(
                  AppConstants.logoImage,
                  width: 34,
                  height: 34,
                  fit: BoxFit.cover,
                ),
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      AppConstants.appName,
                      style: const TextStyle(
                        color: AppColors.navy,
                        fontSize: 15,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 0.3,
                      ),
                    ),
                    Text(
                      'OFFICIAL QUEUE RECEIPT',
                      style: TextStyle(
                        color: AppColors.inkMuted,
                        fontSize: 9,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 1.4,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          const Divider(color: AppColors.hairline, height: 1),
          const SizedBox(height: 14),
          _QueueNumber(number: ticket.queueNumber),
          const SizedBox(height: 14),
          const Divider(color: AppColors.hairline, height: 1),
          const SizedBox(height: 8),
          _ReceiptRow(label: 'Status', value: ticket.displayStatus),
          _ReceiptRow(label: 'Campus Office', value: ticket.officeName),
          _ReceiptRow(label: 'Purpose of Visit', value: purpose),
          _ReceiptRow(
            label: 'Priority',
            value: ticket.isPwd ? 'PWD' : 'Regular',
          ),
          _ReceiptRow(
            label: 'Date & Time',
            value: _formatFull(ticket.createdAt),
          ),
          if (ticket.isCancelled)
            _ReceiptRow(
              label: 'Cancellation Reason',
              value: ticket.cancelReason?.trim().isNotEmpty ?? false
                  ? ticket.cancelReason!.trim()
                  : 'No reason was provided.',
            ),
          const SizedBox(height: 8),
          const Divider(color: AppColors.hairline, height: 1),
          const SizedBox(height: 8),
          _ReceiptRow(label: 'Student', value: studentName),
          _ReceiptRow(label: 'Student ID', value: studentId),
          _ReceiptRow(label: 'Course', value: course),
          _ReceiptRow(label: 'Year Level', value: yearLevel),
        ],
      ),
    );
  }
}

/// The queue number the server assigned, set large and centred so it reads at
/// a glance - the one value the student came for.
class _QueueNumber extends StatelessWidget {
  const _QueueNumber({required this.number});

  final String number;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: <Widget>[
        Text(
          'QUEUE NUMBER',
          style: TextStyle(
            color: AppColors.inkMuted,
            fontSize: 9,
            fontWeight: FontWeight.w800,
            letterSpacing: 1.6,
          ),
        ),
        const SizedBox(height: 6),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 12),
          decoration: BoxDecoration(
            color: AppColors.primary.withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: AppColors.hairline),
          ),
          child: Text(
            number,
            textAlign: TextAlign.center,
            style: const TextStyle(
              color: AppColors.navy,
              fontSize: 46,
              fontWeight: FontWeight.w900,
              letterSpacing: 1.5,
              height: 1.0,
            ),
          ),
        ),
      ],
    );
  }
}

/// Label/value pair on the white receipt paper.
class _ReceiptRow extends StatelessWidget {
  const _ReceiptRow({
    required this.label,
    required this.value,
  });

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            child: Text(
              label,
              style: const TextStyle(
                color: AppColors.inkMuted,
                fontSize: 11.5,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: const TextStyle(
                color: AppColors.ink,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Secondary bordered action under the receipt.
class _GhostAction extends StatelessWidget {
  const _GhostAction({
    required this.label,
    required this.icon,
    required this.onPressed,
    this.busy = false,
  });

  final String label;
  final IconData icon;
  final VoidCallback? onPressed;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final bool enabled = onPressed != null && !busy;

    return PressScale(
      enabled: enabled,
      onTap: onPressed,
      pressedScale: 0.97,
      child: Container(
        height: 48,
        decoration: BoxDecoration(
          color: enabled
              ? Colors.white.withValues(alpha: 0.08)
              : Colors.white.withValues(alpha: 0.03),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: enabled
                ? Colors.white.withValues(alpha: 0.22)
                : Colors.white.withValues(alpha: 0.08),
          ),
        ),
        child: Center(
          child: busy
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.4,
                    color: Colors.white70,
                  ),
                )
                  : Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Icon(
                      icon,
                      size: 18,
                      color: enabled
                          ? Colors.white
                          : Colors.white.withValues(alpha: 0.35),
                    ),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Text(
                        label,
                        maxLines: 1,
                        softWrap: false,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: enabled
                              ? Colors.white
                              : Colors.white.withValues(alpha: 0.35),
                          fontSize: 12.5,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

String _formatFull(DateTime? when) {
  if (when == null) {
    return '—';
  }

  final String hour = when.hour.toString().padLeft(2, '0');
  final String minute = when.minute.toString().padLeft(2, '0');

  return '${when.year}-${when.month.toString().padLeft(2, '0')}-'
      '${when.day.toString().padLeft(2, '0')} $hour:$minute';
}