import '../models/app_user.dart';
import '../models/campus_catalog.dart';
import '../models/operations.dart';
import '../models/queue_ticket.dart';
import '../models/student_bootstrap.dart';

/// ============================================================
/// QUEUEFLOW TMC — CAMPUS BACKEND
///
/// The one contract every transport in this app answers to.
///
/// There are two implementations and neither is a mock:
///
///   RemoteBackend  the Laravel REST API, over `Authorization: Bearer …`
///   LocalBackend   the seeded on-device campus, for demo mode and tests
///
/// The interface exists so the repository, the console and the password
/// recovery flow never learn which one they are talking to. Everything above
/// it is written against [CampusBackend], which is why swapping a demo build
/// for a live one is a change in one factory rather than in every screen.
///
/// Two shapes are enforced here rather than left to each implementation:
///
///   - Failures are [CampusBackendException]s carrying the sentence to show
///     the reader. The server's own `message` is passed straight through,
///     because it is already written to be shown to a student or an operator.
///     [statusCode] rides along for the callers that must tell two refusals
///     apart - see below.
///
///   - Methods that act on the counter window carry the office id. The local
///     campus can often infer it from the ticket, but a REST call cannot: its
///   routes are office-scoped, and a client that guessed the office would be
///   able to address a counter it was never given.
/// ============================================================

/// A rule the campus refused, with the wording the UI shows.
class CampusBackendException implements Exception {
  const CampusBackendException(
    this.message, {
    this.statusCode,
    this.isTimeout = false,
  });

  final String message;

  /// The HTTP status the server answered with, or null when the failure was
  /// decided on the device.
  ///
  /// Carried rather than discarded because one caller genuinely cannot tell the
  /// two apart from wording alone: a 401 means the token is dead and the reader
  /// must sign in again, while no answer at all means the token may be perfect
  /// and the server simply could not be asked. "Those credentials do not match
  /// our records" and "cannot reach the server" are both sentences to show, but
  /// only one of them means "stop using this session". Dropping the code to
  /// keep the exception tidy would force that decision to be made by pattern
  /// -matching on English text, in two transports, forever.
  final int? statusCode;

  /// Whether this refusal is a rejected credential or token.
  bool get isUnauthenticated => statusCode == 401 || statusCode == 403;

  /// Whether the failure was the transport giving up waiting, rather than
  /// the server answering or the device having no link at all.
  ///
  /// Kept out of the message because the three cases are told apart for a
  /// reader: "cannot reach the server" means the server is not answering,
  /// "taking too long" means it was asked and may still be there, and only
  /// the transport knows which happened. Pattern-matching the English would
  /// mean the wording had to stay identical across every future edit of
  /// both transports, forever.
  final bool isTimeout;

  @override
  String toString() => message;
}

/// The outcome of a password-reset code request.
///
/// [code] is populated only when the transport can hand the code back - the
/// local campus has no mailbox, so it shows the code on screen. The real API
/// mails it, so it answers with [message] and no [code], and the screen says
/// so instead of displaying a number that was never issued.
class ResetCodeIssued {
  const ResetCodeIssued({
    required this.message,
    this.code,
    this.expiresInMinutes = 10,
    this.retryAfterSeconds,
  });

  final String message;

  /// Null when the code was mailed rather than returned.
  final String? code;

  final int expiresInMinutes;

  /// Set when a per-address cool-down refused a resend, so the form can
  /// tell the student to wait instead of silently doing nothing.
  final int? retryAfterSeconds;

  bool get isCoolingDown => retryAfterSeconds != null && retryAfterSeconds! > 0;

  /// True when there is a code on screen to fill in.
  bool get hasCode => code != null && code!.isNotEmpty;
}

/// Everything the app can ask a campus to do.
abstract class CampusBackend {
  /// True for the live API, false for the on-device campus.
  ///
  /// The About screen prints it and the password reset flow reads it, because
  /// "your code is 483920" and "we emailed you a code" need different
  /// wording and only the transport knows which one applies.
  bool get isRemote;

  // ========================================================
  // SESSION
  // ========================================================

  /// Signs in and returns the account the server assigned.
  ///
  /// [identifier] is a Student ID for a student and an email address for
  /// staff and administrators; the server resolves which, so the app never
  /// asks. Implementations return the user and, for a remote transport,
  /// establish the bearer token used by every later call.
  Future<AppUser> signIn({
    required String identifier,
    required String password,
  });

  /// Revokes whatever the current session was.
  ///
  /// A no-op on the local campus: nothing there can be revoked, only
  /// deactivated by an administrator.
  Future<void> signOut();

  /// Re-reads an account.
  ///
  /// Called on launch, and it is what catches an account an administrator
  /// deactivated since the last run, or a session whose token no longer
  /// exists. [user] is ignored by a remote transport, which reads the account
  /// off the token - which is the point of putting it in the signature at all:
  /// the local campus has no token and needs to be told whose row to read.
  Future<AppUser> account(AppUser user);

  // ========================================================
  // SHELL PAYLOADS
  // ========================================================

  Future<StudentBootstrap> studentBootstrap(AppUser user);

  /// The office catalogue as the server has it right now, read for its
  /// `status` switches rather than for the rows themselves.
  ///
  /// The shell payload carries the offices too, but it is fetched when the
  /// session starts and an office can be switched offline at any moment
  /// after that. This is the light call the office screens make to re-read
  /// that switch - on open, on return, on pull-to-refresh, and immediately
  /// before a ticket is submitted - and it is deliberately read-only: a
  /// student may learn that a counter is shut, never change it.
  Future<List<Office>> officeCatalogue(AppUser user);

  Future<StaffWorkspace> staffWorkspace(AppUser user);

  Future<AdminOverview> adminOverview();

  /// Every Registrar course queue with its live slot counters.
  ///
  /// Separate from the office catalogue on purpose: the catalogue is narrowed to
  /// the student's own college, which is what keeps `POST /tickets` from
  /// accepting an arbitrary office, and a student still needs to see all seven
  /// Registrar queues before choosing one. Read-only — an office id from here
  /// is not authority to queue anywhere.
  Future<RegistrarCourseList> registrarCourses(AppUser user);

  // ========================================================
  // STUDENT TICKETS
  // ========================================================

  Future<({List<QueueTicket> tickets, TicketCounts counts})> studentTickets(
    AppUser user,
  );

  Future<({QueueTicket ticket, QueueProgress queue})> studentTicket(
    AppUser user,
    int ticketId,
  );

  /// Files a ticket on the student's behalf and answers with the full
  /// [CreatedTicket]: the row, the position in line as the server counted it
  /// at the moment of issue, and the sentence written for the receipt.
  ///
  /// The backend is the only judge of whether the office is online, inside its
  /// hours, has a batch with a slot left, and whether this student already
  /// holds a live ticket - a refusal arrives as a [CampusBackendException]
  /// carrying the server's own sentence.
  Future<CreatedTicket> createTicket({
    required AppUser user,
    required int officeId,
    required int serviceId,
    required int courseId,
    String priority,
    String? purpose,
    String? yearLevel,
    String? evidencePath,
  });

  Future<QueueTicket> cancelTicket(AppUser user, int ticketId);

  // ========================================================
  // BOARDS AND COUNTERS
  // ========================================================

  Future<LiveOfficeBoard> liveBoard(int officeId);

  Future<OfficeQueue> officeQueue(int officeId);

  /// Calls the next number at [officeId], or [ticketId] when the operator
  /// named the row rather than pressing "Call next".
  ///
  /// [officeId] is optional only because the local campus can infer it from
  /// the ticket; a remote call refuses without one, since its routes are
  /// office-scoped.
  Future<QueueTicket> callNext({int? officeId, int? ticketId});

  Future<QueueTicket> serveTicket(int ticketId, AppUser actor, {int? officeId});

  Future<QueueTicket> markNoShow(int ticketId, {int? officeId});

  /// Administrator-only: takes any ticket out of the queue.
  Future<QueueTicket> cancelAny(int ticketId);

  // ========================================================
  // ADMIN: ACCOUNTS
  // ========================================================

  Future<List<AppUser>> accounts({String? role, String? search});

  Future<AppUser> createAccount({
    required String name,
    required String email,
    required AppRole role,
    required String password,
    String? phone,
    String? studentId,
    String? course,
    String? block,
    String? yearLevel,

    /// Which counter a staff account works. Required for a staff account by
    /// the server, and named here rather than inferred: an unassigned staff
    /// account reaches a shell it cannot act in, and that is an
    /// administrative mistake worth making impossible.
    int? officeId,
  });

  /// Updates an existing account.
  ///
  /// [role] and [isActive] are required rather than optional because the
  /// server's update is a whole-row write: leaving either out of the body
  /// would demote or deactivate the account by accident. Sending the row's
  /// current values is the safe way to change one field.
  Future<AppUser> updateAccount({
    required int userId,
    required AppRole role,
    required bool isActive,
    String? name,
    String? email,
    String? password,
    String? studentId,
    String? course,
    String? block,
    String? yearLevel,
    String? phone,
    int? officeId,
  });

  // ========================================================
  // PROFILE
  // ========================================================

  /// Saves profile edits for the signed-in account.
  ///
  /// Name and student number are not parameters: only an administrator
  /// may change them, so no client transport accepts them here.
  ///
  /// [actor] is ignored by a remote transport, which edits whoever the token
  /// belongs to. A client that could name somebody else here would be a way
  /// to edit any account in the campus.
  Future<AppUser> updateProfile({
    required AppUser actor,
    String? email,
    String? block,
    String? yearLevel,
    String? phone,
    String? course,
  });

  Future<void> changePassword({
    required AppUser actor,
    required String currentPassword,
    required String password,
  });

  // ========================================================
  // PASSWORD RESET
  // ========================================================

  /// Asks for a reset code for [identifier].
  Future<ResetCodeIssued> requestResetCode(String identifier);

  /// Verifies a reset [code] on the server and returns the single-use reset
  /// token that authorises the new-password step.
  Future<String> verifyCode({
    required String identifier,
    required String code,
  });

  /// Sets [password], gated either by a verified [resetToken] (the stepped
  /// flow) or, for older callers, by the emailed [code] directly.
  Future<void> resetPassword({
    required String identifier,
    String? code,
    String? resetToken,
    required String password,
  });
}
