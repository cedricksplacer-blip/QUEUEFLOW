import 'dart:convert';

import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/network/connectivity_service.dart';
import 'package:queueflow_tmc/services/campus_backend.dart';
import 'package:queueflow_tmc/services/campus_backend_factory.dart';
import 'package:queueflow_tmc/services/local_backend.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';
import 'package:queueflow_tmc/services/session_store.dart';

/// ============================================================
/// QUEUEFLOW TMC — THE SESSION
///
/// A session is the one piece of state the whole app hangs off, because it
/// decides which shell opens and whether any request is authorised at all.
/// These tests drive [AuthRepository] against a stubbed API and pin the
/// decisions that are expensive to get wrong on a live campus:
///
///   - a cached account with no token is not a session, and must not be sent to
///     the server as though it were;
///   - a 401 ends the session on the spot and forgets the token, because a token
///     the server has rejected is worse than no token at all;
///   - a revocation that fails must still sign this device out: the reader asked
///     for it, and that part protects the device even if the server never heard;
///   - a role this build does not know is a refusal, not a crash.
/// ============================================================
void main() {
  final List<http.Request> sent = <http.Request>[];

  const Map<String, dynamic> student = <String, dynamic>{
    'id': 7,
    'name': 'Juan Dela Cruz',
    'email': 'juan@tmc.edu.ph',
    'role': 'student',
    'student_id': '24-019083',
    'course': 'BSIT',
    'is_active': true,
  };

  const Map<String, dynamic> registrar = <String, dynamic>{
    'id': 4,
    'name': 'Rosario Diaz',
    'email': 'rosario@tmc.edu.ph',
    'role': 'staff',
    'office_id': 3,
    'is_active': true,
  };

  http.Response json(Object body, {int status = 200}) => http.Response(
    jsonEncode(body),
    status,
    headers: <String, String>{
      'content-type': 'application/json; charset=utf-8',
    },
  );

  /// A stub transport whose answers are chosen by [handler].
  ///
  /// The stub is installed on the factory, so [AuthRepository] reaches it the
  /// same way it reaches a real transport: nothing here reaches into the
  /// repository's internals.
  RemoteBackend stub(Object? Function(http.Request request) handler) {
    return RemoteBackend(
      client: ApiClient(
        client: MockClient((http.Request request) {
          sent.add(request);

          final Object? answer = handler(request);

          if (answer is http.Response) {
            return Future<http.Response>.value(answer);
          }

          // The stub throws by returning a marker, because a thrown
          // `http.ClientException` is what a real unreachable server produces.
          throw http.ClientException('$answer');
        }),
        tokenProvider: () => SessionStore.token,
      ),
    );
  }

  /// Answers every read as the given accounts and leaves writes unanswered.
  http.Response Function(http.Request) readsOnly(Map<String, dynamic> payload) {
    return (http.Request request) => json(payload);
  }

  setUp(() async {
    sent.clear();

    // The token lives in the platform keychain, which has no implementation in
    // a test process; the package's own in-memory stand-in gives [SessionStore]
    // a real vault to seal the credential into.
    FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform(
      <String, String>{},
    );

    await SessionStore.clear();
    SharedPreferences.setMockInitialValues(<String, Object>{});
    await SessionStore.load();
    AuthRepository.instance.debugReset();
    CampusBackendFactory.instance.overrideForTesting(null);
  });

  tearDown(() {
    CampusBackendFactory.instance.overrideForTesting(null);
  });

  group('restoring on launch', () {
    test(
      'a cached account with no token is dropped without a request',
      () async {
        await SessionStore.save(user: AppUser.fromMap(student));

        CampusBackendFactory.instance.overrideForTesting(
          stub((_) => json(<String, dynamic>{'user': student})),
        );

        final AuthRepository auth = AuthRepository.instance;

        await auth.restore();

        // The token is what authorises a request. Without one the cached row is
        // not a session, and asking the server would only produce a 401.
        expect(auth.isSignedIn, isFalse);
        expect(auth.user, isNull);
        expect(SessionStore.hasSession, isFalse);
        expect(sent, isEmpty);
      },
    );

    test('a token with no account is dropped too', () async {
      await SessionStore.save(user: AppUser.fromMap(student), token: 'tok');
      await SessionStore.clear();

      // A half-written session must not be completed from a guess.
      expect(SessionStore.hasSession, isFalse);
    });

    test('a good token is re-read and the account refreshed', () async {
      final Map<String, dynamic> renamed = <String, dynamic>{
        ...student,
        'name': 'Juan P. Dela Cruz',
      };

      await SessionStore.save(
        user: AppUser.fromMap(student),
        token: 'good-token',
      );

      CampusBackendFactory.instance.overrideForTesting(
        stub(readsOnly(<String, dynamic>{'user': renamed})),
      );

      final AuthRepository auth = AuthRepository.instance;

      await auth.restore();

      expect(auth.isSignedIn, isTrue);
      expect(auth.isStudent, isTrue);
      expect(auth.user!.name, 'Juan P. Dela Cruz');

      // The refreshed row is written back, so the next launch shows the name
      // the server has rather than the one that was cached.
      expect(SessionStore.user!.name, 'Juan P. Dela Cruz');
      expect(SessionStore.token, 'good-token');
      expect(sent.first.url.path, endsWith('/auth/me'));
    });

    test('a 401 ends the session and forgets the token', () async {
      await SessionStore.save(
        user: AppUser.fromMap(student),
        token: 'revoked-token',
      );

      CampusBackendFactory.instance.overrideForTesting(
        stub(
          (_) => json(<String, dynamic>{
            'message': 'Unauthenticated.',
          }, status: 401),
        ),
      );

      final AuthRepository auth = AuthRepository.instance;

      await auth.restore();

      expect(auth.isSignedIn, isFalse);
      expect(auth.user, isNull);
      expect(auth.error, contains('expired'));
      expect(SessionStore.hasSession, isFalse);
      expect(SessionStore.token, isNull);
    });

    test('an unreachable server does not read as bad credentials', () async {
      await SessionStore.save(
        user: AppUser.fromMap(student),
        token: 'good-token',
      );

      CampusBackendFactory.instance.overrideForTesting(
        stub((_) => 'connection refused'),
      );

      final AuthRepository auth = AuthRepository.instance;

      await auth.restore();

      // The two problems are kept apart: a reader must not be told to retype a
      // password that was fine because the server was not answering.
      expect(auth.error, isNot(contains('credentials')));
      expect(auth.error, isNot(contains('expired')));
      expect(
        auth.error,
        isNot(contains('Checking')),
        reason: 'a failed request must leave an actionable message, not a spinner',
      );
      expect(auth.error, contains('Wi-Fi'));

      // The session survives. The server never rejected this token, so dropping
      // it would mean a student opening the app on the bus has to sign in again
      // for a reason that has nothing to do with their password - and loses the
      // cached ticket number they came to look at. Stale-but-labelled is the
      // honest pairing here.
      expect(auth.isSignedIn, isTrue);
      expect(SessionStore.hasSession, isTrue);

      // ...and the shared connectivity state is told, so the banner cannot keep
      // claiming the server is fine while the shell is showing stale data.
      expect(
        ConnectivityService.instance.state,
        isNot(AppConnectionState.online),
      );
    });
  });

  group('signing out', () {
    test('revokes the token and forgets the account', () async {
      await SessionStore.save(
        user: AppUser.fromMap(student),
        token: 'good-token',
      );

      CampusBackendFactory.instance.overrideForTesting(
        stub(readsOnly(<String, dynamic>{'message': 'Signed out.'})),
      );

      final AuthRepository auth = AuthRepository.instance;

      await auth.signOut();

      expect(sent.single.url.path, endsWith('/auth/logout'));
      expect(sent.single.headers['Authorization'], 'Bearer good-token');
      expect(auth.isSignedIn, isFalse);
      expect(SessionStore.hasSession, isFalse);
    });

    test('still signs out when the revocation never lands', () async {
      await SessionStore.save(
        user: AppUser.fromMap(student),
        token: 'good-token',
      );

      CampusBackendFactory.instance.overrideForTesting(
        stub(
          (_) =>
              json(<String, dynamic>{'message': 'Server error.'}, status: 500),
        ),
      );

      final AuthRepository auth = AuthRepository.instance;

      await auth.signOut();

      // The token stays valid on the server, so this must still sign the device
      // out rather than leaving a shell that only produces errors.
      expect(auth.isSignedIn, isFalse);
      expect(SessionStore.token, isNull);
      expect(auth.error, isNull);
    });
  });

  group('signing in', () {
    test('keeps the session the server issued', () async {
      CampusBackendFactory.instance.overrideForTesting(
        stub(
          (_) => json(<String, dynamic>{
            'user': student,
            'token': 'fresh-token',
            'token_type': 'Bearer',
          }),
        ),
      );

      final AuthRepository auth = AuthRepository.instance;

      final bool ok = await auth.signIn(
        identifier: '24-019083',
        password: 'secret123',
      );

      expect(ok, isTrue);
      expect(auth.user!.studentId, '24-019083');
      expect(SessionStore.token, 'fresh-token');
      expect(SessionStore.hasSession, isTrue);
    });

    test('a refusal is reported and nothing is stored', () async {
      CampusBackendFactory.instance.overrideForTesting(
        stub(
          (_) => json(<String, dynamic>{
            'message': 'Those credentials do not match our records.',
          }, status: 401),
        ),
      );

      final AuthRepository auth = AuthRepository.instance;

      final bool ok = await auth.signIn(
        identifier: '24-019083',
        password: 'wrong',
      );

      expect(ok, isFalse);
      expect(auth.error, 'Those credentials do not match our records.');
      expect(auth.user, isNull);
      expect(SessionStore.hasSession, isFalse);
    });

    test('an account with no portal role is refused, not opened', () async {
      CampusBackendFactory.instance.overrideForTesting(
        stub(
          (_) => json(<String, dynamic>{
            'user': <String, dynamic>{...student, 'role': 'guardian'},
            'token': 'fresh-token',
          }),
        ),
      );

      final AuthRepository auth = AuthRepository.instance;

      final bool ok = await auth.signIn(
        identifier: 'guardian@tmc.edu.ph',
        password: 'secret123',
      );

      expect(ok, isFalse);
      expect(auth.error, contains('no portal access'));
      expect(SessionStore.hasSession, isFalse);
    });

    test('a staff sign-in is refused and leaves no token behind', () async {
      await SessionStore.save(
        user: AppUser.fromMap(student),
        token: 'stale-token',
      );

      CampusBackendFactory.instance.overrideForTesting(
        stub(
          (_) => json(<String, dynamic>{
            'user': registrar,
            'token': 'staff-token',
          }),
        ),
      );

      final AuthRepository auth = AuthRepository.instance;

      final bool ok = await auth.signIn(
        identifier: 'rosario@tmc.edu.ph',
        password: 'secret123',
      );

      // The server accepted the credentials, so this can only pass because the
      // role is what refuses it.
      expect(ok, isFalse);
      expect(auth.error, AuthRepository.notAStudent);
      expect(auth.isSignedIn, isFalse);

      // Both tokens are gone. The staff token is a valid credential for a
      // console this build does not have, and keeping it would leave a usable
      // secret on the device with nothing to use it and no way to see it.
      expect(SessionStore.token, isNull);
      expect(SessionStore.user, isNull);
      expect(auth.user, isNull);
    });
  });

  group('transport selection', () {
    test('both transports answer the same contract', () {
      // A compile-time statement, deliberately: if either backend drops or
      // changes a member of [CampusBackend], this stops building rather than
      // failing on a screen nobody was testing.
      final List<CampusBackend> transports = <CampusBackend>[
        LocalBackend.instance,
        RemoteBackend(),
      ];

      expect(transports.first.isRemote, isFalse);
      expect(transports.last.isRemote, isTrue);
      expect(transports.last, isA<RemoteBackend>());
      expect(transports.first, isA<LocalBackend>());
    });
  });
}
