/// ============================================================
/// QUEUEFLOW TMC — DATE FORMATTING
///
/// Hand-rolled rather than pulled from `intl`: the app needs exactly two
/// shapes, and a locale package for two of them is a dependency that has to be
/// kept current for no benefit.
///
/// Every helper here works on a local [DateTime]. The models already convert
/// with `QfValue.toLocalDate`, so nothing downstream has to think about
/// whether it is holding UTC.
/// ============================================================
library;

const List<String> _months = <String>[
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

class WhenText {
  WhenText._();

  static String _two(int value) => value.toString().padLeft(2, '0');

  static String _clock(DateTime when) =>
      '${_two(when.hour)}:${_two(when.minute)}';

  /// `12 Mar 2026`
  static String date(DateTime when) =>
      '${when.day} ${_months[when.month - 1]} ${when.year}';

  /// `12 Mar 2026, 14:05`
  static String dateTime(DateTime when) => '${date(when)}, ${_clock(when)}';

  /// `14:05`
  static String time(DateTime when) => _clock(when);

  /// How long ago something happened, in the words a person would use.
  ///
  /// Coarse on purpose: "2h ago" is what a notification list wants, and a
  /// student reading a queue time does not need it to the second. Anything
  /// under a minute reads as "Just now" rather than as "0m ago", and a
  /// timestamp in the future — a clock-skewed server, a ticket called a moment
  /// before this device's clock caught up — reads as "Just now" too rather
  /// than as a nonsensical negative interval.
  static String relative(DateTime when, {DateTime? now}) {
    final DateTime reference = now ?? DateTime.now();
    final Duration gap = reference.difference(when);

    if (gap.isNegative || gap.inMinutes < 1) {
      return 'Just now';
    }

    if (gap.inMinutes < 60) {
      return '${gap.inMinutes}m ago';
    }

    if (gap.inHours < 24) {
      return '${gap.inHours}h ago';
    }

    if (gap.inDays < 7) {
      return '${gap.inDays}d ago';
    }

    return date(when);
  }
}
