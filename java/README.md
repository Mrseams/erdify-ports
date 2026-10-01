# erdify-java

Java port of [erdify](https://github.com/devsuit-berlin/erdify) for **Spring Boot**. Not started yet: this is the plan.

```bash
jbang erdify@Mrseams src/main/java -o docs/erd
mvn io.github.mrseams:erdify-maven-plugin:generate
./gradlew erdify
```

Modules (groupId `io.github.mrseams`):

| Artifact | What |
|---|---|
| `erdify-core` | Model, renderers, `--check`/`--inject`, JPA and Spring Data JDBC parsers (JavaParser) |
| `erdify-sql` | Optional Flyway `V*__*.sql` parser (JSqlParser) |
| `erdify-cli` | picocli fat jar, also published to the JBang catalog |
| `erdify-maven-plugin` | `generate`, and `check`, which runs during `verify` |
| `erdify-gradle-plugin` | `erdify`, and `erdifyCheck`, which runs with `check` |

Parses `@Entity`, `@Table`, `@Id`/`@EmbeddedId`, `@Column`, `@JoinColumn`, `@ManyToOne`/`@OneToOne`/`@OneToMany`/`@ManyToMany` + `@JoinTable`, `@Enumerated`, `@MappedSuperclass` and `@Embeddable`.
Table names follow Spring Boot's naming rule by default (`--naming spring`); plain Hibernate keeps names as written (`--naming hibernate`).
This is a build-time tool: nothing is added to your application at runtime.
Correctness is defined by [`../spec`](../spec/README.md).
