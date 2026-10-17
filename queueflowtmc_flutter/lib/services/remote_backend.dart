import '../core/app_config.dart';
import '../core/app_constants.dart';
import '../models/app_user.dart';
import '../models/campus_catalog.dart';
import '../models/operations.dart';
import '../models/queue_ticket.dart';
import '../models/student_bootstrap.dart';
import '../core/api/api_client.dart';
import 'campus_backend.dart';
import 'session_store.dart';

/// ============================================================
/// QUEUEFLOW TMC — REMOTE BACKEND
///
/// The live Laravel API, implemented against [CampusBackend].
///
/// This class does no queueing of its own. Every rule it seems to enforce -
/// "only a waiting ticket can be cancelled", "that service does not belong to
/// this office" - is decided by QueueTicketCreator, QueueCounter or
/// QueueDeploymentManager on the server, and arrives back as a sentence in an
/// `ApiException`. Duplicating any of it here would create a second answer
/// for a question the server already decides, and the two would disagree
/// exactly when it mattered.
///
/// So the whole job is turning paths into calls and payloads into models:
///
///   POST /auth/login                    -> the account and a bearer token
///   GET  /student/bootstrap             -> the student shell
///   GET  /staff/bootstrap               -> the counter shell
///   GET  /admin/overview                -> the campus overview
///   GET  /tickets, /tickets/{id}        -> the student's own queue
///   POST /tickets                       -> take a ticket (multipart)
///   GET  /queues/{office}               -> the counter working set
///   POST /queues/{office}/call-next     -> promote a number
///   GET  /accounts, POST/PATCH          -> account administration
///
/// EVERY authenticated call is office-scoped or user-scoped on the server,
/// and nothing here decides scope. The ids come from the payloads the server
/// sent, so a client cannot address an office it was not granted by
/// constructing one.
/// ============================================================

class RemoteBackend implements CampusBackend {
  RemoteBackend({ApiClient? client})
    : _api = _RemoteApi(client ?? ApiClient(tokenProvider: () => SessionStore.token));

  /// Translates the HTTP layer's failures into the contract's failures.
  ///
  /// [CampusBackend] promises that every failure is a [CampusBackendException]
  /// carrying the sentence to show, and callers rely on that: AuthRepository
  /// catches `CampusBackendException` and nothing else, so a raw [ApiException]
  /// escaping a method is not caught anywhere and tears out of a call the
  /// repository believed it had handled - leaving the reader on a spinner with
  /// no message, or failing a sign-in that was supposed to be reported.
  ///
  /// Wrapping at the boundary rather than wrapping each of the ~25 call sites
  /// means the guarantee cannot be forgotten in a method added later, which is
  /// how it was being forgotten.
  final _RemoteApi _api;

  @override
  bool get isRemote => true;

  // ========================================================
  // SESSION
  // ========================================================

  @override
  Future<AppUser> signIn({
    required String identifier,
    required String password,
  }) async {
    final Map<String, dynamic> body = await _api.post(
      '/auth/login',
      body: <String, dynamic>{
        'student_id': identifier,
        'password': password,
        'device_name': AppConfig.deviceName,
      },
    );

    // The token is stored before the user is returned: everything below this
    // line is an authenticated call, and a shell that opened without one would
    // render and then 401 on its first read.
    await SessionStore.save(
      user: _userOf(body),
      token: body['token']?.toString(),
    );

    return SessionStore.user!;
  }

  @override
  Future<void> signOut() async {
    // Best effort in both directions: a server that cannot be reached must
    // still leave the device signed out, and a token the server has already
    // revoked must not keep the caller waiting on a 401 to find out.
    try {
      await _api.post('/auth/logout');
    } on CampusBackendException {
      // Intentionally ignored - see above.
    }

    await SessionStore.clear();
  }

  @override
  Future<AppUser> account(AppUser user) async {
    final Map<String, dynamic> body = await _api.get('/auth/me');

    final AppUser fresh = _userOf(body);

    await SessionStore.updateUser(fresh);

    return fresh;
  }

  // ========================================================
  // SHELL PAYLOADS
  // ========================================================

  @override
  Future<StudentBootstrap> studentBootstrap(AppUser user) async {
    final Map<String, dynamic> body = await _api.get('/student/bootstrap');

    final StudentBootstrap bootstrap = StudentBootstrap.fromMap(body);

    // The student block carries the account itself, so a profile edited on the
    // server between launches is reflected before the first frame of the
    // student shell rather than after the next refresh.
    await SessionStore.updateStudent(bootstrap.student);

    return bootstrap;
  }

  @override
  Future<List<Office>> officeCatalogue(AppUser user) async {
    // The student argument is not used by the route - `/offices` scopes
    // itself to the caller's token - but it is in the signature so both
    // transports agree on whose session decides what a student may see.
    final Map<String, dynamic> body = await _api.get('/offices');

    return _models(Office.fromMap, body['offices']);
  }

  @override
  Future<StaffWorkspace> staffWorkspace(AppUser user) async {
    final Map<String, dynamic> body = await _api.get('/staff/bootstrap');

    final StaffWorkspace workspace = StaffWorkspace.fromMap(body);

    await SessionStore.updateUser(workspace.user);

    return workspace;
  }

  @override
  Future<AdminOverview> adminOverview() =>
      _map(AdminOverview.fromMap, _api.get('/admin/overview'));

  @override
  Future<RegistrarCourseList> registrarCourses(AppUser user) =>
      _map(RegistrarCourseList.fromMap, _api.get('/registrar/courses'));

  // ========================================================
  // STUDENT TICKETS
  // ========================================================

  @override
  Future<({List<QueueTicket> tickets, TicketCounts counts})> studentTickets(
    AppUser user,
  ) async {
    final Map<String, dynamic> body = await _api.get('/tickets');

    return (
      tickets: _models(QueueTicket.fromMap, body['tickets']),
      counts: TicketCounts.fromMap(_mapOf(body['counts'])),
    );
  }

  @override
  Future<({QueueTicket ticket, QueueProgress queue})> studentTicket(
    AppUser user,
    int ticketId,
  ) async {
    final Map<String, dynamic> body = await _api.get('/tickets/$ticketId');

    final Map<String, dynamic> rawTicket = _mapOf(body['ticket']);
    final QueueTicket ticket = QueueTicket.fromMap(rawTicket);

    // Progress is nested inside the ticket row (`{ticket: {..., progress: {}}}`)
    // and only attached while a ticket is still in line, so an absent key means
    // "no longer waiting" rather than "zero people ahead".
    return (
      ticket: ticket,
      queue: ticket.isWaiting
          ? QueueProgress.fromMap(_mapOf(rawTicket['progress']))
          : const QueueProgress(),
    );
  }

  @override
  Future<CreatedTicket> createTicket({
    required AppUser user,
    required int officeId,
    required int serviceId,
    required int courseId,
    String priority = AppConstants.priorityRegular,
    String? purpose,
    String? yearLevel,
    String? evidencePath,
  }) async {
    // Multipart rather than JSON, because PWD evidence is a file. The server
    // reads every other field off the form the same way, so the two encodings
    // cannot disagree about which office or service was chosen.
    final Map<String, dynamic> body = await _api.multipart(
      '/tickets',
      fields: <String, dynamic>{
        'office_id': officeId,
        'service_id': serviceId,
        'course_id': courseId,
        'priority': priority,
        // `purpose` is required by the server. A student who left it blank gets
        // a neutral line rather than a 422 they cannot act on, because the
        // counter reads this field and "unspecified" is a truthful answer.
        'purpose': _purposeOr(purpose),
        'year_level': yearLevel,
      },
      fileField: 'priority_evidence',
      filePath: evidencePath,
    );

    // The 201 carries all three halves of the issue: the row, the position in
    // line at the moment it was issued, and the sentence the receipt shows.
    // The receipt screen renders exactly these, so nothing here is refetched -
    // the number on screen and the number the counter will call agree because
    // they are the same response.
    return CreatedTicket(
      ticket: QueueTicket.fromMap(_mapOf(body['ticket'])),
      queue: QueueProgress.fromMap(_mapOf(body['progress'])),
      message:
          body['message']?.toString() ?? 'Your ticket number is ready.',
    );
  }

  @override
  Future<QueueTicket> cancelTicket(AppUser user, int ticketId) async {
    final Map<String, dynamic> body = await _api.post(
      '/tickets/$ticketId/cancel',
    );

    return QueueTicket.fromMap(_mapOf(body['ticket']));
  }

  // ========================================================
  // BOARDS AND COUNTERS
  // ========================================================

  @override
  Future<LiveOfficeBoard> liveBoard(int officeId) =>
      _map(LiveOfficeBoard.fromMap, _api.get('/offices/$officeId/live'));

  @override
  Future<OfficeQueue> officeQueue(int officeId) =>
      _map(OfficeQueue.fromMap, _api.get('/queues/$officeId'));

  @override
  Future<QueueTicket> callNext({int? officeId, int? ticketId}) async {
    final Map<String, dynamic> body = await _api.post(
      '/queues/${_requireOffice(officeId)}/call-next',
      body: <String, dynamic>{'ticket_id': ?ticketId},
    );

    return QueueTicket.fromMap(_mapOf(body['ticket']));
  }

  @override
  Future<QueueTicket> serveTicket(
    int ticketId,
    AppUser actor, {
    int? officeId,
  }) async {
    // `actor` is not sent: the server stamps served_by from the token, so a
    // client cannot credit a service to somebody else.
    final Map<String, dynamic> body = await _api.post(
      '/queues/${_requireOffice(officeId)}/tickets/$ticketId/serve',
    );

    return QueueTicket.fromMap(_mapOf(body['ticket']));
  }

  @override
  Future<QueueTicket> markNoShow(int ticketId, {int? officeId}) async {
    final Map<String, dynamic> body = await _api.post(
      '/queues/${_requireOffice(officeId)}/tickets/$ticketId/no-show',
    );

    return QueueTicket.fromMap(_mapOf(body['ticket']));
  }

  @override
  Future<QueueTicket> cancelAny(int ticketId) async {
    final Map<String, dynamic> body = await _api.put(
      '/queues/$ticketId',
      body: <String, dynamic>{'status': AppConstants.statusCancelled},
    );

    return QueueTicket.fromMap(_mapOf(body['ticket']));
  }

  // ========================================================
  // ADMIN: ACCOUNTS
  // ========================================================

  @override
  Future<List<AppUser>> accounts({String? role, String? search}) async {
    final Map<String, dynamic> body = await _api.get(
      '/accounts',
      query: <String, dynamic>{
        if (role != null && role.isNotEmpty) 'role': role,
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );

    return _models(AppUser.fromMap, body['accounts']);
  }

  @override
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
    int? officeId,
  }) async {
    final Map<String, dynamic> body = await _api.post(
      '/accounts',
      body: <String, dynamic>{
        'name': name,
        'email': email,
        'role': role.wire,
        'password': password,
        'is_active': true,
        'phone': phone,
        // Only the columns that belong to the chosen role. The server drops
        // the rest anyway, and sending them would mean an admin create form
        // could quietly put a course on a staff login.
        'student_id': role.isStudent ? studentId : null,
        'course': role.isStudent ? course : null,
        'block': role.isStudent ? block : null,
        'year_level': role.isStudent ? yearLevel : null,
        'office_id': role.isStaff ? officeId : null,
      },
    );

    return _accountOf(body);
  }

  @override
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
  }) async {
    final Map<String, dynamic> body = await _api.patch(
      '/accounts/$userId',
      body: <String, dynamic>{
        'name': name,
        'email': email,
        'role': role.wire,
        'is_active': isActive,
        'password': (password == null || password.isEmpty) ? null : password,
        'phone': phone,
        'student_id': role.isStudent ? studentId : null,
        'course': role.isStudent ? course : null,
        'block': role.isStudent ? block : null,
        'year_level': role.isStudent ? yearLevel : null,
        'office_id': role.isStaff ? officeId : null,
      },
    );

    return _accountOf(body);
  }

  // ========================================================
  // PROFILE
  // ========================================================

  @override
  Future<AppUser> updateProfile({
    required AppUser actor,
    String? email,
    String? block,
    String? yearLevel,
    String? phone,
    String? course,
  }) async {
    // Only email and phone are sent, because those are the only profile
    // columns the server lets an account owner write. Name and student
    // number belong to an administrator editing an account, and the
    // academic columns are ignored server-side rather than accepted.
    final Map<String, dynamic> body = await _api.patch(
      '/auth/me',
      body: <String, dynamic>{'email': email, 'phone': phone},
    );

    final AppUser user = _userOf(body);

    await SessionStore.updateUser(user);

    return user;
  }

  @override
  Future<void> changePassword({
    required AppUser actor,
    required String currentPassword,
    required String password,
  }) async {
    // The server revokes every token on success, this one included, so the
    // local session is dropped rather than left holding a token that is
    // already dead.
    await _api.post(
      '/auth/password',
      body: <String, dynamic>{
        'current_password': currentPassword,
        'password': password,
        'password_confirmation': password,
      },
    );

    await SessionStore.clear();
  }

  // ========================================================
  // PASSWORD RESET
  // ========================================================

  @override
  Future<ResetCodeIssued> requestResetCode(String identifier) async {
    final Map<String, dynamic> body = await _api.post(
      '/auth/forgot-password',
      // `identifier` accepts a Student ID or an email address; the server
      // resolves the account and mails the address the admin saved on it.
      // Sending a Student ID as `email` would fail the server's email rule
      // with a 422, so the versioned field name is used for both shapes.
      body: <String, dynamic>{'identifier': identifier},
    );

    // No `code` is read here, because the server never sends one: the code is
    // emailed, so the response carries only the sentence to show and, when a
    // resend was too early, how long to wait.
    return ResetCodeIssued(
      message: body['message']?.toString() ?? 'A reset code is on its way.',
      expiresInMinutes: _intOf(body['expires_in_minutes'], 10),
      retryAfterSeconds: _intOrNullOf(body['retry_after']),
    );
  }

  @override
  Future<String> verifyCode({
    required String identifier,
    required String code,
  }) async {
    final Map<String, dynamic> body = await _api.post(
      '/auth/verify-code',
      body: <String, dynamic>{'identifier': identifier, 'code': code.trim()},
    );

    final String? token = body['reset_token']?.toString();

    if (token == null || token.isEmpty) {
      throw const CampusBackendException('The code could not be verified.');
    }

    return token;
  }

  @override
  Future<void> resetPassword({
    required String identifier,
    String? code,
    String? resetToken,
    required String password,
  }) => _api.post(
    '/auth/reset-password',
    body: <String, dynamic>{
      'identifier': identifier,
      ...?code == null ? null : {'code': code},
      ...?resetToken == null ? null : {'reset_token': resetToken},
      'password': password,
      'password_confirmation': password,
    },
  );

  // ========================================================
  // INTERNALS
  // ========================================================

  /// The office a counter action is addressed to.
  ///
  /// The console always has one loaded before it offers a button, so a null
  /// here means a caller reached a counter action with nothing selected. The
  /// local campus says "Pick a counter first."; this says the same thing
  /// rather than building a path with a hole in it.
  static int _requireOffice(int? officeId) {
    if (officeId == null || officeId <= 0) {
      throw const CampusBackendException('Pick a counter first.');
    }

    return officeId;
  }

  Future<T> _map<T>(
    T Function(Map<String, dynamic>) parse,
    Future<Map<String, dynamic>> call,
  ) async => parse(await call);

  /// Reads a nested map, defaulting to an empty one.
  ///
  /// An absent key is normal rather than exceptional here: the presenter omits
  /// `batch` when an office has none, and omits `progress` once a ticket has
  /// left the line. Both are already `Map?` on the model side.
  Map<String, dynamic> _mapOf(dynamic raw) =>
      raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{};

  /// Decodes a list of model payloads, dropping nothing.
  ///
  /// A non-map entry would mean the server broke its own contract, and a
  /// silently shorter list would hide that rather than surface it, so the
  /// decode is explicit rather than a tear-off. A missing list is not a
  /// failure: a section the presenter had nothing to say about is simply
  /// empty.
  List<T> _models<T>(T Function(Map<String, dynamic>) parse, dynamic raw) =>
      raw is List
      ? raw
            .whereType<Map>()
            .map((Map row) => parse(Map<String, dynamic>.from(row)))
            .toList(growable: false)
      : const <Never>[];

  AppUser _userOf(Map<String, dynamic> body) =>
      AppUser.fromMap(_mapOf(body['user']));

  AppUser _accountOf(Map<String, dynamic> body) =>
      AppUser.fromMap(_mapOf(body['account']));

  static String _purposeOr(String? purpose) {
    final String trimmed = purpose?.trim() ?? '';

    return trimmed.isEmpty ? 'General transaction' : trimmed;
  }

  static int _intOf(dynamic value, int fallback) =>
      _intOrNullOf(value) ?? fallback;

  static int? _intOrNullOf(dynamic value) =>
      value == null ? null : int.tryParse(value.toString());
}

/// The [ApiClient] as [CampusBackend] callers see it.
///
/// Same methods, same results, one difference: an [ApiException] never leaves.
/// Only the sentence crosses, because that is the part [CampusBackend]
/// guarantees, and a `StatusCode` or a `failure` enum on an exception thrown
/// past this boundary is detail belonging to the transport, not to the
/// repository above it.
class _RemoteApi {
  _RemoteApi(this._client);

  final ApiClient _client;

  Future<Map<String, dynamic>> get(
    String endpoint, {
    Map<String, dynamic>? query,
  }) => _guard(() => _client.get(endpoint, query: query));

  Future<Map<String, dynamic>> post(
    String endpoint, {
    Map<String, dynamic>? body,
  }) => _guard(() => _client.post(endpoint, body: body));

  Future<Map<String, dynamic>> put(
    String endpoint, {
    Map<String, dynamic>? body,
  }) => _guard(() => _client.put(endpoint, body: body));

  Future<Map<String, dynamic>> patch(
    String endpoint, {
    Map<String, dynamic>? body,
  }) => _guard(() => _client.patch(endpoint, body: body));

  Future<Map<String, dynamic>> multipart(
    String endpoint, {
    Map<String, dynamic>? fields,
    String? fileField,
    String? filePath,
    String method = 'POST',
  }) => _guard(
    () => _client.multipart(
      endpoint,
      fields: fields,
      fileField: fileField,
      filePath: filePath,
      method: method,
    ),
  );

  Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw CampusBackendException(
        error.message,
        statusCode: error.statusCode,
        // Carried because the office screens tell "the server is gone" and
        // "the server is slow" apart for the reader, and this is the only
        // place that still knows which one the transport saw.
        isTimeout: error.isTimeout,
      );
    }
  }
}
